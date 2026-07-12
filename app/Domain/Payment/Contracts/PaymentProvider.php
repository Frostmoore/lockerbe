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
    /** Prepara il pagamento: ritorna cosa mostrare al cliente (QR, link, riferimento). */
    public function create(Session $session): PaymentInstruction;

    /**
     * Gestisce la notifica del provider (webhook, o il bottone mock).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): PaymentResult;

    public function refund(Payment $payment): void;
}
