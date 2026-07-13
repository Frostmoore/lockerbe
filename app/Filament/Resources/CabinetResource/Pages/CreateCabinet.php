<?php

namespace App\Filament\Resources\CabinetResource\Pages;

use App\Domain\Cabinet\Services\CabinetService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\CabinetResource;
use App\Models\Cabinet;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * ⚠️ Un armadio NON si crea con `Cabinet::create()`.
 *
 * Si crea con `CabinetService`, che nella stessa transazione genera anche i vani e la loro
 * **mappa RS-485** (quale scheda, quale canale). Un armadio senza vani è un armadio che non
 * apre niente; dei vani senza mappa sono vani che il chiosco non sa dove andare a cercare.
 *
 * ⚠️ E il numero di vani non è un attributo del modello: è un fatto fisico (quante porte ha
 * quella lamiera), e si dichiara una volta sola.
 */
class CreateCabinet extends CreateRecord
{
    protected static string $resource = CabinetResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vani = (int) ($this->data['locker_count'] ?? 1);

        /** @var array{name: string, code: string} $attributi */
        $attributi = [
            'name' => (string) $data['name'],
            'code' => (string) $data['code'],
        ];

        $service = app(CabinetService::class);

        // Nel pannello di piattaforma il contesto è in **bypass**: nessun tenant. Creare
        // così produrrebbe un armadio con `tenant_id` nullo — un armadio di nessuno, che
        // nessun locale vedrebbe mai più. Il tenant scelto nel form diventa il contesto.
        $tenantId = $this->data['tenant_id'] ?? null;

        if (is_string($tenantId) && $tenantId !== '') {
            /** @var Cabinet */
            return app(TenantContext::class)->runForTenant(
                $tenantId,
                fn (): Cabinet => $service->create($attributi, $vani),
            );
        }

        return $service->create($attributi, $vani);
    }
}
