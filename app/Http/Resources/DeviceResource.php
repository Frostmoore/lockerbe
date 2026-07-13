<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * ⚠️ `credential_fingerprint` non viene esposto: e' l'impronta delle credenziali con cui
     * il device si autentica al broker MQTT. Non serve a nessun client, e cio' che non si
     * espone non si perde.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial' => $this->serial,
            'model' => $this->model,
            'mqtt_client_id' => $this->mqtt_client_id,
            'firmware_version' => $this->firmware_version,
            'ip_address' => $this->ip_address,
            'mac_address' => $this->mac_address,
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'cabinet_id' => $this->cabinet_id,
            'activated_at' => $this->activated_at?->toIso8601String(),

            // ⚠️ Finestra di attivazione aperta: il chiosco, se acceso, sta per ritirare le sue
            // credenziali. Il pannello lo mostra al tecnico ("in attesa del chiosco...").
            'activation_open' => $this->hasOpenActivationWindow(),
            'activation_expires_at' => $this->activation_expires_at?->toIso8601String(),
        ];
    }
}
