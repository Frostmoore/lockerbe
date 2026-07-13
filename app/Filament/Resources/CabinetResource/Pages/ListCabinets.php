<?php

namespace App\Filament\Resources\CabinetResource\Pages;

use App\Filament\Resources\CabinetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCabinets extends ListRecords
{
    protected static string $resource = CabinetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuovo armadio')];
    }
}
