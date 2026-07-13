<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Domain\Device\Services\DeviceProvisioningService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\DeviceResource;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * ⚠️ Un chiosco NON si crea con `Device::create()`.
 *
 * Si registra con `DeviceProvisioningService`, che genera il `mqtt_client_id`, mette lo
 * stato a `registered` (**non** `active`: registrato non vuol dire ancora ammesso) e scrive
 * nell'audit chi lo ha messo dentro.
 *
 * Il segreto di firma non nasce qui: nasce quando si preme **Attiva**. Un dispositivo
 * registrato e mai attivato non ha credenziali, e il broker lo rifiuta.
 */
class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $attore */
        $attore = auth()->user();

        $service = app(DeviceProvisioningService::class);

        $serial = (string) $data['serial'];
        $modello = isset($data['model']) ? (string) $data['model'] : null;

        $cabinetId = $data['cabinet_id'] ?? null;
        $tenantId = $this->data['tenant_id'] ?? null;

        $registra = function () use ($service, $serial, $modello, $cabinetId, $attore): Device {
            $armadio = is_string($cabinetId) && $cabinetId !== ''
                ? Cabinet::query()->find($cabinetId)
                : null;

            return $service->register($serial, $modello, $armadio, $attore);
        };

        // Nel pannello di piattaforma il contesto è in bypass: senza tenant, il chiosco
        // nascerebbe di nessuno.
        if (is_string($tenantId) && $tenantId !== '') {
            /** @var Device */
            return app(TenantContext::class)->runForTenant($tenantId, $registra);
        }

        return $registra();
    }
}
