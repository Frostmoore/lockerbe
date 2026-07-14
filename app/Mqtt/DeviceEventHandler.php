<?php

namespace App\Mqtt;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Services\CommandIssuer;
use App\Domain\Identity\Contracts\IdentityProvider;
use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Identity;
use App\Models\Payment;
use App\Models\Session;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cio' che il device racconta al server (piano §9).
 *
 * Eventi: `cmd.ack` · `heartbeat` · `locker.opened` · `locker.closed` · `locker.error` ·
 * `identity.presented`.
 *
 * ⚠️ **Ogni evento viene gestito DENTRO il tenant del suo armadio.** Un handler che girasse in
 * bypass potrebbe, per un id sbagliato in un payload, toccare i dati di un altro locale.
 */
final class DeviceEventHandler
{
    /**
     * ⚠️ Quanti tentativi di identificazione sbagliati, per armadio, prima di chiudere.
     *
     * Sei cifre sono un milione di combinazioni: da sole sarebbero poche. Con 5 tentativi ogni
     * 10 minuti, provarle tutte richiederebbe piu' di trent'anni davanti a quella lamiera.
     */
    private const MAX_TENTATIVI = 5;

    private const FINESTRA_TENTATIVI = 600;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CommandIssuer $commands,
        private readonly SessionManager $sessions,
        private readonly IdentityProvider $identities,
        private readonly PaymentProvider $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Cabinet $cabinet, array $payload): void
    {
        $tipo = (string) ($payload['type'] ?? '');

        $this->context->runForTenant($cabinet->tenant_id, function () use ($cabinet, $tipo, $payload): void {
            match ($tipo) {
                'heartbeat' => $this->heartbeat($cabinet, $payload),
                'cmd.ack' => $this->commandAck($payload),
                'locker.opened', 'locker.closed', 'locker.error' => $this->lockerEvent($cabinet, $tipo, $payload),
                'identity.presented' => $this->identityPresented($cabinet, $payload),

                // 💳 Il cliente ha pagato con la carta, al chiosco. ⚠️ A confermare i soldi e' il
                // PROVIDER, non il device: vedi cardPayment().
                'payment.card' => $this->cardPayment($cabinet, $payload),
                default => $this->audit->log('device.event_unknown', [
                    'cabinet_id' => $cabinet->id,
                    'actor_type' => 'device',
                    'result' => 'fail',
                    'context' => ['type' => $tipo],
                ]),
            };
        });
    }

    /**
     * 💓 Il device e' vivo.
     *
     * ⚠️ E' cio' che rende l'armadio **raggiungibile**: senza heartbeat, ogni apertura
     * risponde 409 e nessun comando viene accodato (§8.4). E' la difesa che fa il suo mestiere.
     *
     * @param  array<string, mixed>  $payload
     */
    private function heartbeat(Cabinet $cabinet, array $payload): void
    {
        $cabinet->forceFill([
            'status' => $cabinet->status === 'maintenance' ? 'maintenance' : 'online',
            'last_seen_at' => now(),
            'firmware_version' => $payload['fw'] ?? $cabinet->firmware_version,
        ])->save();

        $cabinet->device()->first()?->forceFill([
            'status' => 'online',
            'last_seen_at' => now(),
            'firmware_version' => $payload['fw'] ?? null,
            'ip_address' => $payload['ip'] ?? null,
        ])->save();
    }

    /**
     * 📟 Il device ha eseguito (o rifiutato) un comando.
     *
     * ⚠️ Se il device dice di aver **rifiutato** un comando scaduto o mal firmato, non e' un
     * errore: e' la seconda meta' della difesa che funziona. Va registrato come tale.
     *
     * @param  array<string, mixed>  $payload
     */
    private function commandAck(array $payload): void
    {
        $command = Command::query()->find((string) ($payload['command_id'] ?? ''));

        if (! $command instanceof Command) {
            return;
        }

        $ok = (bool) ($payload['ok'] ?? false);

        $this->commands->ack($command, $ok, [
            'error' => $payload['error'] ?? null,
            'da_device' => true,
        ]);
    }

    /**
     * 🚪 Lo sportello si e' aperto, chiuso, o ha dato errore.
     *
     * ⚠️ **`locker.closed` e' la conferma vera della riconsegna** (§7.1-bis): finche' non
     * arriva, il vano riconsegnato resta `checkout` e non viene riassegnato. E' il motivo per
     * cui D5 (la scheda sa dire se lo sportello e' chiuso?) conta cosi' tanto: senza, si va a
     * timer, che e' un ripiego.
     *
     * @param  array<string, mixed>  $payload
     */
    private function lockerEvent(Cabinet $cabinet, string $tipo, array $payload): void
    {
        $numero = (int) ($payload['locker'] ?? 0);

        $locker = $cabinet->lockers()->where('number', $numero)->first();

        if ($locker === null) {
            return;
        }

        /*
         * ⚠️ **QUALE COMANDO HA APERTO QUESTO SPORTELLO — E SE NESSUNO, CHI L'HA APERTO?**
         *
         * Il device allega il `command_id` quando apre **perche' glielo e' stato ordinato**. Se
         * manca, quello sportello si e' aperto **senza che nessuno l'abbia comandato**: o un
         * tecnico con la chiave, o la scheda serrature azionata a mano — o qualcuno che sta
         * forzando il vano di un cliente.
         *
         * ⚠️ E' precisamente cio' per cui esiste un registro. Un'apertura senza mandante e' la
         * riga che si va a cercare quando un cappotto non c'e' piu': senza questo campo, tutte
         * le aperture si somigliano e non si puo' dire **chi**.
         *
         * ⚠️ Il `command_id` si **verifica**, non si crede: il device potrebbe (per errore, o
         * perche' compromesso) allegare l'id di un comando di un altro armadio, e si
         * attribuirebbe l'apertura a chi non c'entra niente. Se non risulta un comando di
         * QUESTO armadio, si scarta l'id e si registra come apertura **non comandata** — che e'
         * la lettura piu' prudente: e' meglio un'apertura "a mano" di troppo che una forzatura
         * attribuita a un innocente.
         */
        $comandoId = null;
        $dichiarato = $payload['command_id'] ?? null;

        if (is_string($dichiarato) && $dichiarato !== '') {
            $comandoId = Command::query()
                ->where('id', $dichiarato)
                ->where('cabinet_id', $cabinet->id)
                ->value('id');
        }

        $this->audit->log($tipo, [
            'cabinet_id' => $cabinet->id,
            'locker_id' => $locker->id,
            'command_id' => $comandoId,
            'actor_type' => 'device',
            'result' => $tipo === 'locker.error' ? 'fail' : 'ok',
            'context' => $payload,
        ]);

        if ($tipo !== 'locker.closed' || $locker->status !== 'checkout') {
            return;
        }

        // ⚠️ Lo sportello del vano in riconsegna e' stato richiuso: **e' la conferma che il
        // cliente ha svuotato**. Il vano torna libero — e stavolta non per un timer, ma perche'
        // il mondo fisico ce l'ha detto.
        $session = Session::query()
            ->where('locker_id', $locker->id)
            ->where('status', 'active')
            ->whereNotNull('checkout_pending_at')
            ->first();

        if ($session instanceof Session) {
            $this->sessions->confirmCheckout($session, 'device');
        }
    }

    /**
     * 🪪 IL CLIENTE SI IDENTIFICA — con la carta, o digitando il codice ricevuto per email.
     *
     * ⚠️ **Qui non si lega piu' niente.** L'identita' nasce DAL PAGAMENTO (`IdentityIssuer`).
     *
     * Prima, davanti a un token sconosciuto, questo metodo lo legava "alla sessione attiva piu'
     * recente dell'armadio". Era il rattoppo di un buco piu' grande — chi pagava col QR non
     * riceveva **nessuna** identita' — ed era **peggio del buco**: con due clienti che
     * depositano di fila, la carta del primo apriva il vano del secondo.
     *
     * Chi arriva qui ha gia' un'identita', creata quando ha pagato: il **codice a 6 cifre**
     * mandato per email, o il **token della carta** restituito dal provider. Il chiosco non
     * distingue: manda una stringa, e il server sa a chi appartiene.
     *
     * @param  array<string, mixed>  $payload
     */
    private function identityPresented(Cabinet $cabinet, array $payload): void
    {
        $token = (string) ($payload['token'] ?? '');
        $intent = (string) ($payload['intent'] ?? 'reopen');

        if ($token === '') {
            return;
        }

        /*
         * ⚠️ TROPPI TENTATIVI SBAGLIATI SU QUESTO ARMADIO.
         *
         * Sei cifre sono un milione di combinazioni: **da sole sarebbero poche**. Senza un
         * freno, un pomeriggio di tentativi automatici le prova tutte, e il codice smette di
         * essere una chiave.
         *
         * Il freno vive **per armadio**, perche' e' li' che l'attacco avviene: davanti a quella
         * lamiera, con quel touchscreen. E si somma alle altre due difese: il codice vale solo
         * per QUELL'armadio, e solo finche' la sessione e' viva.
         */
        if (RateLimiter::tooManyAttempts(self::chiaveTentativi($cabinet), self::MAX_TENTATIVI)) {
            $this->audit->log('identity.throttled', [
                'cabinet_id' => $cabinet->id,
                'actor_type' => 'device',
                'result' => 'fail',
                'error_code' => 'too_many_attempts',
            ]);

            return;
        }

        $session = $this->identities->resolve($token, $cabinet);

        if (! $session instanceof Session) {
            RateLimiter::hit(self::chiaveTentativi($cabinet), self::FINESTRA_TENTATIVI);

            // ⚠️ Identita' sconosciuta ⇒ **non si apre niente**. E' l'asimmetria (§7.0): davanti
            // a un dubbio, un vano non si tocca. Finisce nel registro, perche' e' esattamente
            // cio' che vede il cliente a cui "non succede nulla".
            $this->audit->log('identity.unmatched', [
                'cabinet_id' => $cabinet->id,
                'actor_type' => 'device',
                'result' => 'fail',
                'error_code' => 'unknown_identity',
                'context' => ['intent' => $intent],
            ]);

            return;
        }

        // Identita' giusta: il contatore dei tentativi si azzera.
        RateLimiter::clear(self::chiaveTentativi($cabinet));

        // ⚠️ L'intento e' stato dichiarato **prima** di identificarsi (§7.1), e non si perde per
        // strada: "ho finito" vuol dire finito.
        if ($intent === 'checkout') {
            $this->sessions->checkout($session);

            return;
        }

        $identity = $session->identities()
            ->where('token_hash', Identity::hashToken($token))
            ->first();

        if ($identity instanceof Identity) {
            $this->sessions->reopen($session, $identity);
        }
    }

    /**
     * 💳 IL CLIENTE HA PAGATO CON LA CARTA, al chiosco.
     *
     * ⚠️⚠️ **A dire che i soldi sono arrivati e' il PROVIDER, non il device.** Il chiosco
     * presenta la carta; il token e l'esito escono da `PaymentProvider::handleCardPayment()`.
     * Se bastasse la parola del chiosco, un chiosco compromesso potrebbe dichiarare "questa
     * carta ha pagato" e regalarsi tutti i vani dell'armadio.
     *
     * ⚠️ Il `card_token` finisce nel payload del pagamento, ed e' da li' che `IdentityIssuer`
     * lo prende: **l'identita' nasce dal pagamento**, non da un tap a parte.
     *
     * ⚠️ E il `session_id` arriva dalla rete: si verifica che sia di **questo** armadio e ancora
     * **da pagare**. Un chiosco compromesso che indicasse la sessione di un altro locale se la
     * prenderebbe.
     *
     * @param  array<string, mixed>  $payload
     */
    private function cardPayment(Cabinet $cabinet, array $payload): void
    {
        $sessionId = $payload['session_id'] ?? null;
        $cardToken = $payload['card_token'] ?? null;

        $session = is_string($sessionId) && $sessionId !== ''
            ? Session::query()
                ->where('id', $sessionId)
                ->where('cabinet_id', $cabinet->id)
                ->where('status', 'created')
                ->first()
            : null;

        if (! $session instanceof Session || ! is_string($cardToken) || $cardToken === '') {
            $this->audit->log('payment.card', [
                'cabinet_id' => $cabinet->id,
                'actor_type' => 'device',
                'result' => 'fail',
                'error_code' => 'no_session_to_pay',
            ]);

            return;
        }

        /** @var Payment $payment */
        $payment = $session->payment()->firstOrFail();

        /*
         * ⚠️⚠️ QUESTA CARTA TIENE GIA' UN VANO? Allora **non si incassa**.
         *
         * Il controllo sta QUI, **prima** di chiamare il provider, e non e' pignoleria: se lo
         * facessimo dopo, avremmo preso i soldi di un cliente a cui poi dobbiamo dire di no —
         * e ci resterebbe un rimborso da fare a mano.
         *
         * Perche' una carta puo' tenere un vano solo:
         *
         *  - se ne aprisse due, **chi la trovasse per terra avrebbe le chiavi di entrambi**;
         *  - e al tap il sistema non saprebbe *quale* aprire. La risoluzione prende la sessione
         *    piu' recente: il primo vano diventerebbe **irraggiungibile con la propria carta**.
         *    Il cliente ha pagato, il cappotto e' dentro, e solo lo staff puo' tirarlo fuori.
         *
         * La sessione si annulla: il vano riservato torna **libero** invece di restare bloccato
         * per il timeout della prenotazione. E il chiosco, che sta guardando lo stato della
         * sessione, se ne accorge e lo dice al cliente.
         */
        if ($this->identities->hasActiveSession($cardToken)) {
            $this->sessions->failPayment($payment);

            $this->audit->log('payment.card', [
                'cabinet_id' => $cabinet->id,
                'locker_id' => $session->locker_id,
                'session_id' => $session->id,
                'actor_type' => 'device',
                'result' => 'fail',
                'error_code' => 'card_already_in_use',
            ]);

            return;
        }

        $esito = $this->payments->handleCardPayment([
            'provider_ref' => $payment->provider_ref,
            'card_token' => $cardToken,
        ]);

        $payment->forceFill(['payload' => $esito->payload])->save();

        $this->sessions->confirmPayment($payment);
    }

    private static function chiaveTentativi(Cabinet $cabinet): string
    {
        return 'identity:'.$cabinet->id;
    }
}
