<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Session\Services\SessionManager;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\Session;
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

        /*
         * ⚠️ COME VUOLE PAGARE decide TUTTO il flusso di identita', quindi si chiede subito:
         *
         *   qr   → paga sul telefono, lascia l'email, riceve un **codice a 6 cifre**;
         *   nfc  → paga con la carta, e il token che il provider restituisce **e'** l'identita'.
         *
         * Il default e' `qr`: e' l'unico dei due che oggi funziona di sicuro (sull'NFC pende un
         * vincolo hardware aperto — vedi PaymentProvider).
         */
        $dati = $request->validate([
            'method' => ['sometimes', 'in:qr,nfc'],
        ]);

        $metodo = (string) ($dati['method'] ?? 'qr');

        // ⚠️ Il pagamento lo crea SessionManager, non piu' questo controller: qualunque altra
        // strada per aprire una sessione produceva altrimenti una sessione senza pagamento,
        // cioe' una sessione che non si puo' confermare.
        [
            'session' => $session,
            'token' => $token,
            'payment' => $istruzione,
        ] = $this->sessions->request($cabinet, null, $metodo);

        return new JsonResponse([
            'session_id' => $session->id,
            'locker_number' => $session->locker()->firstOrFail()->number,
            'public_token' => $token,
            'payment_method' => $metodo,
            'payment' => [
                'id' => $session->payment_id,
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
     * ⚠️ COM'E' FINITA LA SESSIONE CHE STO SERVENDO?
     *
     * Il chiosco non sa da solo se il cliente ha pagato sul telefono, ne' se il server ha
     * rifiutato la carta: deve chiederlo. E deve chiederlo **a questa rotta**, non a quella
     * pubblica del cliente.
     *
     * ⚠️ **Perche' esiste, ed e' una lezione pagata.** Prima il chiosco interrogava
     * `/public/sessions/{token}` ogni 2 secondi. Quella rotta ha un rate limit stretto — **10
     * richieste al minuto** — perche' il token pubblico e' l'unica cosa che separa un estraneo
     * dal cappotto di qualcun altro, e senza limite lo si cercherebbe a forza bruta. Il chiosco
     * ne faceva **30 al minuto**: dopo venti secondi scattava il 429, il `fetch` riceveva un
     * corpo d'errore invece dello stato, e il chiosco **restava muto per sempre**. Il cliente
     * vedeva la schermata di pagamento e nient'altro.
     *
     * Il chiosco e' autenticato **come device**: non ha nessun bisogno di passare dalla porta
     * di servizio pensata per un estraneo con un token in mano.
     *
     * ⚠️ E la sessione dev'essere **del proprio armadio**: l'id arriva dalla rete.
     */
    public function sessionStatus(Request $request, string $session): JsonResponse
    {
        $cabinet = $this->cabinetOf($request);

        $sessione = Session::query()
            ->where('id', $session)
            ->where('cabinet_id', $cabinet->id)
            ->firstOrFail();

        return new JsonResponse([
            'status' => $sessione->status,
            'locker_number' => $sessione->locker()->first()?->number,
            'payment_method' => $sessione->payment_method,
        ]);
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
