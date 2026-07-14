<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Contracts\IdentityProvider;
use App\Mail\CodiceAccesso;
use App\Models\Identity;
use App\Models\Payment;
use App\Models\Session;
use Illuminate\Support\Facades\Mail;
use Random\RandomException;
use Throwable;

/**
 * ⚠️ L'IDENTITA' NASCE DAL PAGAMENTO. Non dopo, non "quando capita".
 *
 * E' il punto in cui il sistema decide *chi* potra' riaprire quel vano, ed e' l'unico
 * momento in cui puo' saperlo con certezza: chi ha appena pagato **e' li' davanti**.
 *
 * Prima non era cosi', ed era il bug che si vedeva dal chiosco: il cliente pagava col QR e
 * non gli veniva data nessuna identita'. Poi premeva "ho finito", passava una carta a caso,
 * e non succedeva niente — il vano restava occupato. Il rattoppo di allora (legare la carta
 * sconosciuta "alla sessione attiva piu' recente") era **peggio del buco**: con due clienti
 * di fila, la carta del primo apriva il vano del secondo.
 *
 * Due strade, decise dal metodo di pagamento:
 *
 *   QR   → **codice a 6 cifre** mandato per email. Sei cifre sono un milione di
 *          combinazioni: da sole sarebbero poche, ed e' per questo che il codice vale **solo
 *          per quell'armadio**, **solo finche' la sessione e' viva**, e che i tentativi
 *          sbagliati sono contati (vedi `DeviceEventHandler`).
 *
 *   NFC  → il **token di carta** che restituisce il provider di pagamento. La stessa carta,
 *          riappoggiata, produce lo stesso token: e' quello a fare da scontrino.
 *
 * ⚠️ Nel database non finisce mai il codice, ne' il token: solo il loro **SHA-256**. Chi
 * legge il database non deve poter aprire i vani.
 */
final class IdentityIssuer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly IdentityProvider $identities,
    ) {}

    /**
     * Dopo che il pagamento e' confermato: dai al cliente il modo di riaprire il suo vano.
     *
     * ⚠️ Chiamato **da dentro** `SessionManager::confirmPayment()`, cioe' da un solo posto.
     * Sembra un dettaglio e non lo e': chiunque confermi un pagamento — la pagina mock, il
     * chiosco con la carta, domani il webhook di Nexi — passa di li'. Se l'identita' si
     * creasse nei chiamanti, basterebbe un chiamante nuovo che se ne dimentica per avere di
     * nuovo clienti che non possono riprendersi il cappotto.
     */
    public function issueFor(Session $session, Payment $payment): void
    {
        if ($session->payment_method === 'nfc') {
            $this->daCarta($session, $payment);

            return;
        }

        $this->daCodice($session);
    }

    /**
     * ⚠️ IL TOKEN DELLA CARTA LO DA' IL PROVIDER, NON IL DEVICE.
     *
     * Sta dentro il payload del pagamento, cioe' arriva **con la conferma dei soldi**. Se
     * lasciassimo che a dircelo fosse il chiosco, un chiosco compromesso potrebbe dichiarare
     * "questa carta ha pagato" e prendersi un vano gratis. Il device presenta la carta; a dire
     * che il pagamento e' andato a buon fine — e con che token — e' il provider.
     */
    private function daCarta(Session $session, Payment $payment): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $payment->payload;

        $token = $payload['card_token'] ?? null;

        if (! is_string($token) || $token === '') {
            // ⚠️ Pagamento riuscito ma nessun token: il cliente ha il vano e **non potra'
            // riaprirlo da solo**. Va nel registro forte e chiaro, perche' e' un caso in cui
            // lo staff dovra' intervenire a mano — e in cui il provider ci ha dato meno di
            // quello che ci serviva.
            $this->audit->log('identity.issue', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $session->locker_id,
                'session_id' => $session->id,
                'result' => 'fail',
                'error_code' => 'no_card_token',
            ]);

            return;
        }

        /*
         * ⚠️ RETE DI SICUREZZA: una carta tiene **al piu' un vano alla volta**.
         *
         * Il controllo vero sta prima, in `DeviceEventHandler::cardPayment()`, dove si puo'
         * ancora **non incassare**. Se scatta qui vuol dire che qualcuno ha confermato un
         * pagamento per un'altra strada senza controllare: i soldi sono gia' presi, e non c'e'
         * piu' niente di buono da fare.
         *
         * Ma **creare il doppione sarebbe peggio**: la risoluzione prende la sessione piu'
         * recente, quindi il vano precedente — pagato, pieno — diventerebbe irraggiungibile
         * con la sua stessa carta, in silenzio. Meglio nessuna identita' e una riga urlata nel
         * registro: almeno lo staff sa che deve intervenire.
         */
        if ($this->identities->hasActiveSession($token)) {
            $this->audit->log('identity.issue', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $session->locker_id,
                'session_id' => $session->id,
                'result' => 'fail',
                'error_code' => 'card_already_in_use',
            ]);

            return;
        }

        Identity::create([
            'session_id' => $session->id,
            'type' => 'nfc_card',
            'token_hash' => Identity::hashToken($token),
        ]);

        $this->audit->log('identity.issue', [
            'cabinet_id' => $session->cabinet_id,
            'locker_id' => $session->locker_id,
            'session_id' => $session->id,
            'context' => ['method' => 'nfc'],
        ]);
    }

    /**
     * Il codice a 6 cifre, mandato per email.
     *
     * ⚠️ **L'email si ACCODA, non si manda qui.** Questo metodo gira dentro la transazione che
     * conferma il pagamento: mandarla in linea significa legare l'incasso alla salute di un
     * server SMTP. Vedi il commento accanto alla `Mail::queue()`, qui sotto: e' la ragione di un
     * bug vero.
     *
     * ⚠️ Quando ci sara' un provider di posta esterno (Brevo, Mailgun, SES) **non cambia una
     * riga di questo file**: cambiano le variabili d'ambiente.
     */
    private function daCodice(Session $session): void
    {
        $codice = self::generaCodice();

        Identity::create([
            'session_id' => $session->id,
            'type' => 'access_code',
            'token_hash' => Identity::hashToken($codice),
        ]);

        $email = $session->customer_email;

        if (! is_string($email) || $email === '') {
            // ⚠️ Ha pagato e non ci ha lasciato l'email: il codice esiste ma non ha dove
            // andare. Non e' un caso teorico — e' un campo di form saltato — e il cliente si
            // ritrova con un vano che non sa riaprire. Lo staff deve poterlo scoprire.
            $this->audit->log('identity.issue', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $session->locker_id,
                'session_id' => $session->id,
                'result' => 'fail',
                'error_code' => 'no_customer_email',
            ]);

            return;
        }

        /*
         * ⚠️⚠️ **UN'EMAIL NON PUO' ANNULLARE UN PAGAMENTO RIUSCITO.**
         *
         * Questo metodo gira **dentro la transazione** di `confirmPayment()`. Prima si mandava
         * la mail in modo **sincrono**, qui: bastava che l'SMTP tossisse — un certificato
         * scaduto, il relay giu', la rete lenta — e l'eccezione faceva **rollback di tutto**.
         *
         * Il cliente aveva pagato, e si ritrovava: un **500**, nessuna sessione, e il vano
         * bloccato su `reserved` finche' non scadeva la prenotazione. E' esattamente cosi' che
         * l'abbiamo trovato in produzione.
         *
         * Ora si **accoda** (`queue`), e in piu' si **ingoia qualunque errore**: se nemmeno
         * l'accodamento riesce (Redis giu'), il pagamento resta valido lo stesso. La consegna
         * di un'email e' un servizio *accessorio*: non ha nessun diritto di disfare l'incasso.
         *
         * ⚠️ E se il codice non parte davvero, il cliente non puo' riaprire il suo vano. Per
         * questo il fallimento **finisce nel registro** invece di sparire: lo staff lo vede, e
         * puo' aprirgli il vano dal pannello. Un errore ingoiato in silenzio sarebbe peggio del
         * 500.
         */
        try {
            Mail::to($email)->queue(new CodiceAccesso(
                codice: $codice,
                numeroVano: (int) $session->locker()->firstOrFail()->number,
            ));
        } catch (Throwable $e) {
            $this->audit->log('identity.issue', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $session->locker_id,
                'session_id' => $session->id,
                'result' => 'fail',
                'error_code' => 'mail_queue_failed',
                'context' => ['method' => 'qr', 'email' => $email, 'errore' => $e->getMessage()],
            ]);

            return;
        }

        // ⚠️ Nel registro finisce che il codice e' stato mandato, **non il codice**. Un audit
        // log che contiene le chiavi di casa e' un audit log che apre i vani.
        $this->audit->log('identity.issue', [
            'cabinet_id' => $session->cabinet_id,
            'locker_id' => $session->locker_id,
            'session_id' => $session->id,
            'context' => ['method' => 'qr', 'email' => $email],
        ]);
    }

    /**
     * Sei cifre, da `random_int` — non da `rand()`.
     *
     * ⚠️ `rand()` e `mt_rand()` sono prevedibili: chi vede qualche codice puo' calcolare i
     * successivi. Sei cifre indovinabili sono sei cifre inutili.
     *
     * @throws RandomException
     */
    public static function generaCodice(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
