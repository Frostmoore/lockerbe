<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\PaymentInstruction;
use App\Domain\Payment\PaymentResult;
use App\Models\Payment;
use App\Models\Session;

/**
 * Il confine fra il dominio e il mondo dei pagamenti.
 *
 * Esiste per una ragione pratica: **D1 non e' ancora decisa** (Nexi online/QR vs terminale
 * certificato) e il cliente non ha dato le credenziali. Senza questo contratto, tutto il
 * sistema sarebbe fermo ad aspettare.
 *
 * Il passaggio al pagamento reale (F8) deve costare: implementare questa interfaccia e
 * cambiare `LOCKER_PAYMENT_DRIVER`. **Nient'altro.** Se un giorno servisse toccare
 * SessionManager per far entrare Nexi, vorrebbe dire che questo contratto era sbagliato.
 *
 * ⚠️ Vincolo accertato: il pagamento con carta EMV **non e' fattibile sul FCV5003** (niente
 * secure element, niente certificazione PCI, DejaOS non esegue SoftPOS). Il device resta
 * fuori dal perimetro pagamento in entrambe le varianti di D1.
 */
interface PaymentProvider
{
    /**
     * Prepara il pagamento: ritorna cosa mostrare al cliente (QR, link, riferimento).
     *
     * ⚠️ `$publicToken` e' il token della sessione, e serve perche' **il QR porti a una pagina
     * vera** — quella su cui il cliente paga e lascia l'email. Un QR che contiene uno schema
     * fantasia (`locker://…`) e' un QR che nessun telefono sa aprire: il flusso che il cliente
     * vive davvero non esisterebbe.
     */
    public function create(Session $session, ?string $publicToken = null): PaymentInstruction;

    /**
     * Gestisce la notifica del provider (webhook, o il bottone mock).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): PaymentResult;

    /**
     * ⚠️ IL PAGAMENTO CON LA CARTA, e il **token** che ne esce.
     *
     * Il provider, incassando, restituisce un identificativo **stabile** di quella carta —
     * `card_token` — e **quello diventa l'identita' della sessione**: la stessa carta,
     * riappoggiata, riapre il vano. Non serve nessun altro scontrino.
     *
     * ⚠️ **Chi dice che i soldi sono arrivati e' il PROVIDER, non il device.** Il chiosco
     * presenta la carta e nient'altro. Se bastasse la parola del chiosco, un chiosco
     * compromesso potrebbe dichiarare "questa carta ha pagato" e regalarsi i vani.
     *
     * ⚠️⚠️ **Vincolo aperto**: l'EMV *sul* FCV5003 era stato accertato come NON fattibile (no
     * secure element, no PCI, DejaOS non esegue SoftPOS). Il cliente riferisce che il provider
     * dica il contrario. Questo contratto non prende posizione — e' esattamente il suo mestiere.
     * **Ma va fatto confermare che il token torni stabile anche a IMPORTO ZERO**: senza,
     * l'unico modo di identificare un cliente alla riapertura sarebbe fargli ripagare il
     * guardaroba (D1 · D2 · D3).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCardPayment(array $payload): PaymentResult;

    public function refund(Payment $payment): void;
}
