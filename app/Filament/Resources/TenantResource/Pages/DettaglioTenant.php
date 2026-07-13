<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\CabinetResource;
use App\Filament\Resources\TenantResource;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\Tenant;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * IL LOCALE, con dentro tutto il suo ferro.
 *
 * ⚠️ Serve a rispondere alla domanda che si fa il supporto al telefono: *"cos'ha, questo
 * cliente?"* — e la risposta non è "un nome e uno slug": è **quanti armadi ha, quali sono
 * accesi, e quali chioschi ci stanno dentro**.
 *
 * ⚠️ Le query girano **senza global scope** (`withoutGlobalScopes`), e non è una scorciatoia:
 * questa pagina esiste solo nel pannello di piattaforma, dove il contesto è in bypass e il
 * filtro tenant non c'è. Ma il contesto in bypass filtrerebbe *niente*, non *questo tenant* —
 * quindi il tenant lo mettiamo noi, esplicitamente, con una `where`. Senza, si vedrebbero gli
 * armadi di tutti dentro la scheda di uno.
 */
class DettaglioTenant extends Page
{
    // ⚠️ Vedi `NodiCabinet`: `$record` deve poter contenere una stringa, perché Livewire ci
    // riassegna l'id a ogni re-render. Il trait la tiene `Model|int|string` apposta.
    use InteractsWithRecord;

    protected static string $resource = TenantResource::class;

    protected string $view = 'filament.resources.tenant.dettaglio';

    public function mount(string|int $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('view', $this->locale()) ?? false, 403);
    }

    public function locale(): Tenant
    {
        /** @var Tenant */
        return $this->getRecord();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->locale()->name;
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    /** @return Collection<int, Cabinet> */
    public function armadi(): Collection
    {
        return Cabinet::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->locale()->id)
            ->with('device')
            ->withCount([
                'lockers',
                'lockers as lockers_liberi_count' => fn ($q) => $q->where('status', 'free'),
                'lockers as lockers_rotti_count' => fn ($q) => $q->where('status', 'out_of_service'),
            ])
            ->orderBy('code')
            ->get();
    }

    /**
     * ⚠️ **Tutti** i chioschi, non solo quelli legati a un armadio.
     *
     * Un chiosco registrato e mai associato è invisibile se lo si cerca partendo dagli armadi —
     * e invece è proprio quello che il tecnico ha appena messo dentro e sta cercando.
     *
     * @return Collection<int, Device>
     */
    public function chioschi(): Collection
    {
        return Device::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->locale()->id)
            ->with('cabinet')
            ->orderBy('serial')
            ->get();
    }

    public function urlNodi(Cabinet $cabinet): string
    {
        return CabinetResource::getUrl('nodi', ['record' => $cabinet]);
    }
}
