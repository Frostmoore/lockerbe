<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Session\Services\SessionManager;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\Payment;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Le API del CHIOSCO — quelle che chiama il dispositivo, non una persona.
 *
 * ⚠️ Autenticate **come device**, non come utente: il chiosco non ha ruoli ne' permessi, ha
 * un token e un armadio. E il cliente che ci sta davanti **non ha un account** (§4): non deve
 * fare login per depositare un cappotto.
 *
 * ⚠️ Il chiosco puo' agire **solo sul proprio armadio**. Non lo sceglie: glielo dice la sua
 * stessa identita'. Un chiosco che potesse indicare un `cabinet_id` a piacere sarebbe un
 * chiosco che, compromesso, apre gli armadi degli altri.
 */
final class KioskController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PaymentProvider $payments,
    ) {}

    /** Lo stato dell'armadio: quanti vani liberi, e quali. */
    public function state(Request $request): JsonResponse
    {
        $cabinet = $this->cabinetOf($request);

        $lockers = $cabinet->lockers()->orderBy('number')->get();

        return new JsonResponse([
            'cabinet' => ['code' => $cabinet->code, 'name' => $cabinet->name],
            'free' => $lockers->where('status', 'free')->count(),
            'total' => $lockers->count(),
            'lockers' => $lockers->map(fn ($l) => [
                'number' => $l->number,
                'status' => $l->status,
            ])->all(),
        ]);
    }

    /**
     * Il cliente chiede un vano. **Nessun account, nessun login.**
     *
     * Restituisce le istruzioni di pagamento (il QR da disegnare a schermo) e il token pubblico
     * — che il chiosco stampa nel QR di riapertura, o consegna come si vuole.
     */
    public function requestLocker(Request $request): JsonResponse
    {
        $cabinet = $this->cabinetOf($request);

        ['session' => $session, 'token' => $token] = $this->sessions->request($cabinet);

        $istruzione = $this->payments->create($session);

        $payment = Payment::create([
            'session_id' => $session->id,
            'provider' => $istruzione->provider,
            'provider_ref' => $istruzione->providerRef,
            'amount_cents' => $istruzione->amountCents,
            'currency' => $istruzione->currency,
            'status' => 'created',
            'payload' => [],
        ]);

        $session->forceFill(['payment_id' => $payment->id])->save();

        return new JsonResponse([
            'session_id' => $session->id,
            'locker_number' => $session->locker()->firstOrFail()->number,
            'public_token' => $token,
            'payment' => [
                'id' => $payment->id,
                'amount_cents' => $istruzione->amountCents,
                'currency' => $istruzione->currency,
                'qr_payload' => $istruzione->qrPayload,

                // ⚠️ Il QR lo disegna il SERVER, non il chiosco.
                //
                // Non e' una scelta estetica: il FCV5003 **non ha un modulo QR nativo** (nel
                // prototipo di lockerfe c'era un PNG statico). Generarlo qui significa che il
                // device deve solo mostrare un'immagine — cosa che sa fare — e che il giorno
                // che arriva Nexi cambia il contenuto, non il chiosco.
                'qr_svg' => $this->qr((string) $istruzione->qrPayload),
            ],
        ], JsonResponse::HTTP_CREATED);
    }

    /** Il QR come SVG inline: nessun file, nessuna rete, nessuna dipendenza sul device. */
    private function qr(string $payload): string
    {
        return (new PngWriter)->write(new QrCode($payload))->getDataUri();
    }

    /**
     * ⚠️ L'armadio lo decide **l'identita' del chiosco**, non la richiesta.
     */
    private function cabinetOf(Request $request): Cabinet
    {
        // ⚠️ L'attore qui e' un DEVICE, non una persona (vedi ResolveTenant).
        /** @var Device|null $device */
        $device = $request->user();

        if (! $device instanceof Device || $device->cabinet_id === null) {
            throw new AccessDeniedHttpException('Questa rotta e\' riservata ai chioschi.');
        }

        return $device->cabinet()->firstOrFail();
    }
}
