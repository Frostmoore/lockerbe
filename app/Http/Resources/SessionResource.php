<?php

namespace App\Http\Resources;

use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Session
 */
class SessionResource extends JsonResource
{
    /**
     * ⚠️ `public_token_hash` non viene MAI esposto, e nemmeno il token in chiaro: quello
     * esiste una volta sola, nella risposta a `POST /sessions`, e chi lo perde deve
     * chiedere allo staff. Esporlo qui significherebbe che chiunque possa leggere una
     * sessione possa anche aprire quel vano.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'cabinet_id' => $this->cabinet_id,
            'locker_id' => $this->locker_id,
            'locker' => new LockerResource($this->whenLoaded('locker')),
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'reopen_count' => $this->reopen_count,
            'reserved_until' => $this->reserved_until->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),

            // Riconsegna dichiarata ma non ancora confermata: il vano e' aperto e resta del
            // cliente. Il pannello lo usa per mostrare "in riconsegna" e offrire allo staff
            // il bottone di conferma.
            'checkout_pending_at' => $this->checkout_pending_at?->toIso8601String(),
            'checkout_pending' => $this->isCheckoutPending(),

            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closed_by,
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
