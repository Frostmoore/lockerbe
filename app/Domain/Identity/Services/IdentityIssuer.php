<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Mail\CodiceAccesso;
use App\Models\Identity;
use App\Models\Payment;
use App\Models\Session;
use Illuminate\Support\Facades\Mail;
use Random\RandomException;

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
    public function __construct(private readonly AuditLogger $audit) {}

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
     * ⚠️ **Non c'e' ancora un provider di posta**: la mail finisce in
     * `storage/logs/laravel.log` (`MAIL_MAILER=log`). Il codice e' vero e funziona. Quando ci
     * sara' un provider **non cambia una riga di questo file**: cambia una variabile
     * d'ambiente.
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

        Mail::to($email)->send(new CodiceAccesso(
            codice: $codice,
            numeroVano: (int) $session->locker()->firstOrFail()->number,
        ));

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
