<?php

namespace App\Mqtt;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Services\CommandIssuer;
use App\Domain\Identity\Contracts\IdentityProvider;
use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Identity;
use App\Models\Session;

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
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommandIssuer $commands,
        private readonly SessionManager $sessions,
        private readonly IdentityProvider $identities,
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

        $this->audit->log($tipo, [
            'cabinet_id' => $cabinet->id,
            'locker_id' => $locker->id,
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
     * 🪪 Il cliente ha presentato la carta.
     *
     * ⚠️ **Stessa code-path del bottone mock.** Cambia solo chi produce il token: la' un
     * bottone, qui il lettore NFC del FCV5003. Se funziona col mock e non con la carta vera, il
     * bug e' nel lettore — non nel dominio.
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
         * ⚠️ IL DEPOSITO: e' QUI che la carta diventa lo scontrino.
         *
         * Prima non era cosi', ed e' il bug che si vedeva dal chiosco: il cliente pagava col
         * QR e **non gli veniva mai chiesta la carta**. Quando poi premeva "ho finito", la
         * carta risultava sconosciuta e non succedeva niente — il vano restava occupato, e dal
         * suo punto di vista il sistema era rotto.
         *
         * Il rattoppo di allora era peggio del buco: la carta sconosciuta veniva legata "alla
         * sessione attiva piu' recente dell'armadio". Con due clienti che depositano di fila,
         * **la carta del primo apriva il vano del secondo**.
         *
         * Ora la carta si presenta al deposito, e il chiosco dice **a quale sessione** —
         * quella che sta servendo in questo istante, davanti a quella persona. Nessuna
         * indovinata.
         */
        if ($intent === 'store') {
            $this->legaCarta($cabinet, $token, $payload['session_id'] ?? null);

            return;
        }

        $session = $this->identities->resolve($token, $cabinet);

        if (! $session instanceof Session) {
            // ⚠️ Carta sconosciuta ⇒ **non si apre niente**. E' l'asimmetria (§7.0): davanti a
            // un dubbio, un vano non si tocca. Finisce nel registro, perche' e' esattamente
            // cio' che vede il cliente a cui "non succede nulla".
            $this->audit->log('identity.unmatched', [
                'cabinet_id' => $cabinet->id,
                'actor_type' => 'device',
                'result' => 'fail',
                'error_code' => 'no_session_for_card',
                'context' => ['intent' => $intent],
            ]);

            return;
        }

        // ⚠️ L'intento e' stato dichiarato **prima** di passare la carta (§7.1), e non si perde
        // per strada: "ho finito" vuol dire finito.
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
     * Lega la carta alla sessione che il chiosco sta servendo **adesso**.
     *
     * ⚠️ La sessione la dice il chiosco (`session_id`): e' lui che, un istante fa, ha mostrato
     * il QR a quella persona. Farla indovinare al server ("la piu' recente…") e' esattamente
     * cio' che permetteva alla carta di un cliente di aprire il vano di un altro.
     *
     * ⚠️ E la sessione deve essere **di questo armadio** e **senza carta**: un chiosco
     * compromesso che indicasse una sessione altrui si legherebbe il vano di un altro locale.
     * L'id arriva dalla rete — non ci si fida, si verifica.
     */
    private function legaCarta(Cabinet $cabinet, string $token, mixed $sessionId): void
    {
        $session = is_string($sessionId) && $sessionId !== ''
            ? Session::query()
                ->where('id', $sessionId)
                ->where('cabinet_id', $cabinet->id)
                ->where('status', 'active')
                ->whereDoesntHave('identities')
                ->first()
            : null;

        if (! $session instanceof Session) {
            $this->audit->log('identity.bind', [
                'cabinet_id' => $cabinet->id,
                'actor_type' => 'device',
                'result' => 'fail',
                'error_code' => 'no_session_to_bind',
            ]);

            return;
        }

        $this->identities->bind($session, $token);

        $this->audit->log('identity.bind', [
            'cabinet_id' => $cabinet->id,
            'locker_id' => $session->locker_id,
            'session_id' => $session->id,
            'actor_type' => 'device',
        ]);
    }
}
