<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\PaymentInstruction;
use App\Domain\Payment\PaymentResult;
use App\Models\Payment;
use App\Models\Session;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pagamento finto: due bottoni, "riuscito" e "fallito".
 *
 * Serve a una cosa sola, e la fa bene: **rendere il flusso completo verificabile oggi**,
 * senza Nexi, senza credenziali, senza carte e senza hardware. D1 e' ancora aperta col
 * cliente; senza questo mock, l'intero sistema sarebbe fermo ad aspettare una decisione
 * commerciale.
 *
 * ⚠️ Il `qr_payload` che produce e' finto, ma **passa dalla stessa strada** di quello vero:
 * il chiosco lo disegna e basta. Il giorno che arriva Nexi, il chiosco non cambia di una
 * riga — cambia solo chi ha scritto la stringa.
 */
final class MockPaymentProvider implements PaymentProvider
{
    public function create(Session $session): PaymentInstruction
    {
        $ref = 'mock_'.Str::uuid7()->toString();

        return new PaymentInstruction(
            provider: 'mock',
            providerRef: $ref,
            amountCents: $session->amount_cents,
            currency: $session->currency,
            // Cio' che finirebbe dentro il QR code sullo schermo del chiosco.
            qrPayload: "locker://pay/{$ref}?amount={$session->amount_cents}",
            confirmUrl: "/api/v1/mock/payments/{$ref}/confirm",
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): PaymentResult
    {
        $ref = (string) ($payload['provider_ref'] ?? '');
        $outcome = (string) ($payload['outcome'] ?? 'confirmed');

        if ($ref === '') {
            throw new RuntimeException('provider_ref mancante nel callback mock.');
        }

        return new PaymentResult(
            providerRef: $ref,
            status: $outcome === 'failed' ? 'failed' : 'confirmed',
            payload: $payload,
        );
    }

    public function refund(Payment $payment): void
    {
        $payment->forceFill(['status' => 'refunded'])->save();
    }
}
