<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Filament\Resources\DeviceResource;
use Filament\Resources\Pages\EditRecord;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        // ⚠️ Nessun DeleteAction: un chiosco non si cancella, si REVOCA. Cancellarlo
        // perderebbe la storia di cosa ha aperto e quando — cioe' l'unica cosa che permette
        // di rispondere a "chi ha aperto quel vano?".
        return [DeviceResource::attivaAction(), DeviceResource::revocaAction()];
    }
}
