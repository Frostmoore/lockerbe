<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Session\Services\SessionManager;
use App\Http\Resources\SessionResource;
use App\Models\Cabinet;
use App\Models\Payment;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sessioni: il rapporto fra un cliente e un vano.
 */
final class SessionController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PaymentProvider $payments,
    ) {}

    /**
     * Il cliente chiede un vano. E' il primo passo del flusso.
     *
     * Assegna il primo vano libero, crea il pagamento e restituisce le **istruzioni di
     * pagamento** (oggi un QR finto, domani Nexi) piu' il **token pubblico**.
     *
     * ⚠️ Il token in chiaro compare **solo qui, una volta sola**: nel database ne resta
     * l'hash. E' cio' che il cliente si porta via per riaprire il vano dal telefono senza
     * avere un account. Se lo perde, deve rivolgersi allo staff — ed e' giusto cosi': un
     * token recuperabile sarebbe un token indovinabile.
     *
     * Chiamato oggi dal pannello; in F5 dall'emulatore del chiosco, in FH dal FCV5003.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cabinet_id' => ['required', 'uuid'],
            'amount_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        // Non `findOrFail` diretto: passa dallo scope, quindi un armadio di un altro
        // locale semplicemente non esiste (404).
        $cabinet = Cabinet::query()->whereKey($data['cabinet_id'])->firstOrFail();

        ['session' => $session, 'token' => $token] = $this->sessions->request(
            $cabinet,
            isset($data['amount_cents']) ? (int) $data['amount_cents'] : null,
        );

        $instruction = $this->payments->create($session);

        $payment = Payment::create([
            'session_id' => $session->id,
            'provider' => $instruction->provider,
            'provider_ref' => $instruction->providerRef,
            'amount_cents' => $instruction->amountCents,
            'currency' => $instruction->currency,
            'status' => 'created',
            'payload' => [],
        ]);

        $session->forceFill(['payment_id' => $payment->id])->save();

        // `additional()` e non `toArray()`: chiamare toArray() a mano salta il meccanismo
        // che scarta le relazioni non caricate (whenLoaded), e la risposta esplode.
        return (new SessionResource($session->load('locker')))
            ->additional([
                'public_token' => $token,
                'payment' => [
                    'id' => $payment->id,
                    'provider' => $instruction->provider,
                    'provider_ref' => $instruction->providerRef,
                    'amount_cents' => $instruction->amountCents,
                    'currency' => $instruction->currency,
                    'qr_payload' => $instruction->qrPayload,
                    'confirm_url' => $instruction->confirmUrl,
                ],
            ])
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = Session::query()
            ->with('locker')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('cabinet_id'), fn ($q) => $q->where('cabinet_id', $request->string('cabinet_id')))
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return SessionResource::collection($sessions);
    }

    public function show(Session $session): SessionResource
    {
        return new SessionResource($session->load('locker', 'payment'));
    }

    /** Checkout fatto dallo staff (il cliente e' al bancone). */
    public function checkout(Session $session): JsonResponse
    {
        $commandId = $this->sessions->checkout($session);

        return (new SessionResource($session->refresh()->load('locker')))
            ->additional(['command_id' => $commandId])
            ->response();
    }
}
