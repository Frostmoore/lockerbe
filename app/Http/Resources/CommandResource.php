<?php

namespace App\Http\Resources;

use App\Models\Command;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Command
 */
class CommandResource extends JsonResource
{
    /**
     * ⚠️ `signature` NON viene esposta: e' la prova crittografica che quel comando viene da
     * noi, e serve al device, non a un client HTTP. Cio' che non si espone non si perde.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'reason' => $this->reason,
            'status' => $this->status,
            'cabinet_id' => $this->cabinet_id,
            'locker_id' => $this->locker_id,
            'session_id' => $this->session_id,

            // ⚠️ La scadenza. Il device rifiuta i comandi scaduti, e cosi' fa il server: un
            // comando che sopravvive al proprio senso apre un vano davanti a nessuno.
            'expires_at' => $this->expires_at->toIso8601String(),
            'deliverable' => $this->isDeliverable(),

            'issued_at' => $this->issued_at->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'acked_at' => $this->acked_at?->toIso8601String(),
            'attempts' => $this->attempts,
            'result' => $this->result,
        ];
    }
}
