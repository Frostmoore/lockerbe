<?php

namespace App\Filament\Resources\CabinetResource\Pages;

use App\Filament\Resources\CabinetResource;
use Filament\Resources\Pages\EditRecord;

class EditCabinet extends EditRecord
{
    protected static string $resource = CabinetResource::class;

    /**
     * ⚠️ **IL PREZZO NON PASSA DA QUI SE CHI SALVA NON PUÒ DECIDERLO.**
     *
     * Per un gestore di locale il campo non è nemmeno visibile — ma una richiesta Livewire
     * costruita a mano lo rimetterebbe in `$data`, e Filament lo salverebbe senza fare domande.
     * Nascondere un campo non è impedire di scriverlo *(regola non negoziabile 23)*.
     *
     * Si scarta la chiave, non si solleva un errore: chi arriva fin qui sta forzando, e non
     * merita una diagnostica.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! CabinetResource::puoDecidereIlPrezzo()) {
            unset($data['tariff_cents']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        // ⚠️ Nessun DeleteAction. Un armadio non si cancella dal pannello: si porterebbe
        // dietro vani, sessioni e comandi — cioe' la storia di cosa e' stato aperto e quando.
        // Un armadio dismesso si mette in `maintenance`.
        return [CabinetResource::apriTuttiAction()];
    }
}
