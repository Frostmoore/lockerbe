<?php

namespace App\Http\Resources;

use App\Models\Cabinet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cabinet
 */
class CabinetResource extends JsonResource
{
    /**
     * ⚠️ `status` e' il valore in tabella; `online` e' la verita' calcolata dall'heartbeat.
     * Possono divergere per un minuto (finche' MarkOfflineCabinets non gira), e chi consuma
     * l'API deve poter vedere entrambi: il primo dice cosa crede il database, il secondo
     * cosa e' vero adesso.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'site_id' => $this->site_id,
            'status' => $this->status,
            'online' => $this->isOnline(),
            'firmware_version' => $this->firmware_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'settings' => $this->settings,
            'lockers_count' => $this->whenCounted('lockers'),
            'device' => new DeviceResource($this->whenLoaded('device')),
            'lockers' => LockerResource::collection($this->whenLoaded('lockers')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
