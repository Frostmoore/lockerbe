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
    public function create(Session $session, ?string $publicToken = null): PaymentInstruction
    {
        $ref = 'mock_'.Str::uuid7()->toString();

        /*
         * ⚠️ IL QR PORTA A UNA PAGINA VERA, non a uno schema fantasia.
         *
         * Prima conteneva `locker://pay/...`: una stringa che nessun telefono sa aprire. Andava
         * bene finche' il pagamento lo confermava un bottone del pannello mock — ma il flusso
         * che il cliente vive davvero e' *inquadro, si apre una pagina, pago, lascio l'email*.
         * Se il QR non porta da nessuna parte, quel flusso non esiste.
         *
         * Il giorno che arriva Nexi, qui dentro ci finisce il **loro** link: il chiosco continua
         * a disegnare un'immagine e non cambia di una riga.
         */
        return new PaymentInstruction(
            provider: 'mock',
            providerRef: $ref,
            amountCents: $session->amount_cents,
            currency: $session->currency,
            qrPayload: $publicToken !== null
                ? url('/pay/'.$publicToken)
                : "locker://pay/{$ref}?amount={$session->amount_cents}",
            confirmUrl: "/api/v1/mock/payments/{$ref}/confirm",
        );
    }

    /**
     * ⚠️ IL TOKEN DELLA CARTA — ed e' il pezzo che rende possibile la riapertura in NFC.
     *
     * Il provider, incassando, restituisce un identificativo **stabile** di quella carta: la
     * stessa carta, riappoggiata domani, produce lo stesso token. E' quello a fare da scontrino.
     *
     * ⚠️⚠️ **Sul FCV5003 il pagamento EMV era stato dato per NON fattibile** (no secure element,
     * no PCI, DejaOS non esegue SoftPOS). Il cliente riferisce che il provider dica il contrario.
     * Questo codice non prende posizione: sta dietro il contratto `PaymentProvider`, quindi se
     * hanno ragione si innesta il loro driver e nient'altro si muove. **Resta da farsi confermare
     * che il token torni stabile anche a IMPORTO ZERO**: senza, la riapertura non funziona — si
     * potrebbe identificare un cliente solo facendogli ripagare il guardaroba.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCardPayment(array $payload): PaymentResult
    {
        $ref = (string) ($payload['provider_ref'] ?? '');
        $carta = (string) ($payload['card_token'] ?? '');

        if ($ref === '' || $carta === '') {
            throw new RuntimeException('Il pagamento con carta vuole provider_ref e card_token.');
        }

        return new PaymentResult(
            providerRef: $ref,
            status: 'confirmed',
            payload: ['card_token' => $carta, 'method' => 'nfc'],
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
