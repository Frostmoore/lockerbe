<?php

namespace App\Domain\Payment;

/**
 * Cosa mostrare al cliente per farlo pagare.
 *
 * `qrPayload` e' la stringa che finisce dentro il QR code: oggi la genera il mock, domani
 * Nexi. Il chiosco (emulatore in F5, FCV5003 in FH) la disegna e basta — non sa, e non
 * deve sapere, chi l'ha prodotta.
 */
final readonly class PaymentInstruction
{
    public function __construct(
        public string $provider,
        public string $providerRef,
        public int $amountCents,
        public string $currency,
        public ?string $qrPayload = null,
        public ?string $confirmUrl = null,
    ) {}
}
