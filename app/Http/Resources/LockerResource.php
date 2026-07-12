<?php

namespace App\Http\Resources;

use App\Models\Locker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Locker
 */
class LockerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cabinet_id' => $this->cabinet_id,
            'number' => $this->number,
            'status' => $this->status,
            'assignable' => $this->isAssignable(),

            // Indirizzo RS-485. Esposto perche' serve a diagnosticare un cablaggio
            // sbagliato: se il vano 7 apre il 12, la prima cosa da guardare e' questa.
            'board_address' => $this->board_address,
            'channel' => $this->channel,

            'current_session_id' => $this->current_session_id,
            'last_opened_at' => $this->last_opened_at?->toIso8601String(),
        ];
    }
}
