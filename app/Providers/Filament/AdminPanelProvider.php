<?php

namespace App\Providers\Filament;

use App\Domain\Auth\Middleware\EnsureMfaSatisfiedInPanel;
use App\Domain\Tenancy\Middleware\ResolveTenant;
use App\Filament\Pages\Mfa;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\CabinetResource;
use App\Filament\Resources\CommandResource;
use App\Filament\Resources\DeviceResource;
use App\Filament\Resources\LockerResource;
use App\Filament\Resources\SessionResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\UserResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * IL PANNELLO DI PIATTAFORMA — `/admin`. Noi.
 *
 * Vede **tutti i locali**: e' l'unico posto in cui l'isolamento tra clienti e'
 * deliberatamente assente. Non c'e' un trucco per ottenerlo: un `platform_admin` ha
 * `tenant_id = NULL`, e `ResolveTenant` per lui non stringe niente — il contesto resta in
 * bypass, che e' esattamente lo stato in cui il database mostra tutto.
 *
 * ⚠️ Per questo `canAccessPanel()` (vedi `User`) e' la sola cosa che tiene un utente di un
 * locale fuori da questo URL. Se cedesse, chiunque abbia un account vedrebbe tutti i
 * clienti — non un pezzo, tutto. C'e' un test.
 *
 * ⚠️ `isPersistent: true`: identico al pannello dei locali, e per lo stesso motivo — vedi
 * il commento in testa a `AppPanelProvider`, che e' il file dove quel motivo fa male.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Locker — piattaforma')
            ->login()

            /*
             * ⚠️⚠️ IL DEFAULT DI FILAMENT E' PERICOLOSO, E QUESTA RIGA LO SPEGNE.
             *
             * Di suo, quando Filament chiede a una policy "si puo' fare X?" e la policy
             * **non ha il metodo X**, la risposta e' *si'*. Non e' un'illazione: e' scritto
             * in `get_authorization_response()`, e ce ne siamo accorti perche' un test
             * dimostrava che il pannello era pronto a offrire "crea" e "elimina" **sul
             * registro di audit** — la tabella che esiste apposta perche' nessuno possa far
             * sparire cio' che e' successo.
             *
             * Con `strictAuthorization`, un metodo mancante diventa una **LogicException**
             * rumorosa invece di un permesso silenzioso. Il giorno che qualcuno aggiunge una
             * risorsa e si dimentica la policy, il pannello si rompe — che e' esattamente il
             * comportamento che vogliamo, perche' l'alternativa e' che si apra.
             */
            ->strictAuthorization()
            ->colors(['primary' => Color::Amber])
            ->pages([Dashboard::class, Mfa::class])
            ->resources([
                TenantResource::class,
                CabinetResource::class,
                LockerResource::class,
                DeviceResource::class,
                SessionResource::class,
                CommandResource::class,
                AuditLogResource::class,
                UserResource::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                ResolveTenant::class,
                EnsureMfaSatisfiedInPanel::class,
            ], isPersistent: true);
    }
}
