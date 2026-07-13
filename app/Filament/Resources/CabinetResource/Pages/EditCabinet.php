<?php

namespace App\Filament\Resources\CabinetResource\Pages;

use App\Filament\Resources\CabinetResource;
use Filament\Resources\Pages\EditRecord;

class EditCabinet extends EditRecord
{
    protected static string $resource = CabinetResource::class;

    protected function getHeaderActions(): array
    {
        // ⚠️ Nessun DeleteAction. Un armadio non si cancella dal pannello: si porterebbe
        // dietro vani, sessioni e comandi — cioe' la storia di cosa e' stato aperto e quando.
        // Un armadio dismesso si mette in `maintenance`.
        return [CabinetResource::apriTuttiAction()];
    }
}
