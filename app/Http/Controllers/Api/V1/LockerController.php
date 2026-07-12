<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Http\Resources\LockerResource;
use App\Models\Locker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vani (piano §10).
 *
 * In F2 il vano si puo' guardare e mettere fuori servizio. **Non si puo' ancora aprire**:
 * l'apertura arriva in F4, insieme al TTL, all'idempotenza e al rifiuto verso gli armadi
 * offline. Costruire prima l'apertura e poi le sue difese vorrebbe dire avere, per un po',
 * un endpoint che apre vani senza rete di sicurezza.
 */
final class LockerController
{
    use AuthorizesRequests;

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Locker $locker): LockerResource
    {
        $this->authorize('view', $locker);

        return new LockerResource($locker);
    }

    /**
     * Mette il vano fuori servizio, o ce lo toglie.
     *
     * ⚠️ Un vano `out_of_service` e' **escluso dall'assegnazione automatica** (F3): e' tutto
     * il senso di questo endpoint. Un vano rotto assegnato a un cliente e' un cliente che
     * non riesce piu' a riprendersi il cappotto.
     *
     * ⚠️ Non si puo' mettere fuori servizio un vano **occupato**: dentro c'e' la roba di
     * qualcuno, e toglierlo dal sistema significherebbe perdere il filo di chi deve
     * riaprirlo. Prima si fa il checkout.
     */
    public function update(Request $request, Locker $locker): LockerResource|JsonResponse
    {
        $this->authorize('service', $locker);

        $data = $request->validate([
            'status' => ['required', 'in:free,out_of_service'],
        ]);

        $target = (string) $data['status'];

        if (in_array($locker->status, ['reserved', 'occupied', 'checkout'], true)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'locker_busy',
                    'message' => 'Il vano ha una sessione in corso: fai prima il checkout.',
                    'details' => ['status' => $locker->status],
                ],
            ], JsonResponse::HTTP_CONFLICT);
        }

        $previous = $locker->status;
        $locker->update(['status' => $target]);

        $this->audit->log('locker.service', [
            'cabinet_id' => $locker->cabinet_id,
            'locker_id' => $locker->id,
            'context' => ['from' => $previous, 'to' => $target],
        ]);

        return new LockerResource($locker);
    }
}
