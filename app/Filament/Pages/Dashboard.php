<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CabinetResource;
use App\Models\Cabinet;
use App\Models\Tenant;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * LA HOME: un cartellino per ogni armadio, raggruppati per locale.
 *
 * ⚠️ È il primo posto in cui guarda chi arriva la mattina, e deve rispondere in un colpo
 * d'occhio a **una domanda sola**: *qualcosa è rotto?* Per questo il pallino di stato è la
 * cosa più grande del cartellino, e i vani fuori servizio sono in rosso: se l'armadio è
 * offline, oggi quel guardaroba non apre.
 *
 * ⚠️ **Non filtra il tenant.** In `/app` `ResolveTenant` ha già stretto il contesto e il
 * database restituisce solo gli armadi del proprio locale; in `/admin` il contesto è in
 * bypass e li restituisce tutti, raggruppati per cliente. Stessa query, due risposte — che è
 * il motivo per cui non c'è un `if` qui dentro.
 */
class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Armadi';

    protected string $view = 'filament.pages.dashboard';

    public function getTitle(): string|Htmlable
    {
        return 'Armadi';
    }

    /**
     * Gli armadi, raggruppati per locale.
     *
     * @return array<string, Collection<int, Cabinet>>
     */
    public function locali(): array
    {
        return Cabinet::query()
            ->with(['device', 'tenant'])
            ->withCount([
                'lockers',
                'lockers as lockers_liberi_count' => fn ($q) => $q->where('status', 'free'),
                'lockers as lockers_occupati_count' => fn ($q) => $q->whereIn('status', ['occupied', 'reserved', 'checkout']),
                'lockers as lockers_rotti_count' => fn ($q) => $q->where('status', 'out_of_service'),
            ])
            ->orderBy('code')
            ->get()
            ->groupBy(function (Cabinet $c): string {
                $locale = $c->tenant;

                // ⚠️ Un armadio senza locale non dovrebbe esistere — `BelongsToTenant` lo
                // impedisce alla scrittura. Ma se ce ne fosse uno, va **visto**, non nascosto
                // da un errore: un armadio di nessuno è un armadio che nessuno andrà a
                // riparare.
                return $locale instanceof Tenant ? $locale->name : '— senza locale —';
            })
            ->all();
    }

    /** L'indirizzo della vista a nodi di quell'armadio. */
    public function urlNodi(Cabinet $cabinet): string
    {
        return CabinetResource::getUrl('nodi', ['record' => $cabinet]);
    }
}
