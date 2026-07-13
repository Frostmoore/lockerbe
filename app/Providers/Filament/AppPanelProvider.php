<?php

namespace App\Providers\Filament;

use App\Domain\Auth\Middleware\EnsureMfaSatisfiedInPanel;
use App\Domain\Tenancy\Middleware\ResolveTenant;
use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Mfa;
use App\Filament\Pages\Registro;
use App\Filament\Resources\CabinetResource;
use App\Filament\Resources\DeviceResource;
use App\Filament\Resources\LockerResource;
use App\Filament\Resources\SessionResource;
use App\Filament\Resources\UserResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
 * IL PANNELLO DEL LOCALE — `/app`. Chi lavora al guardaroba.
 *
 * Vede **solo il proprio locale**, e non perche' le risorse filtrino qualcosa: perche'
 * `ResolveTenant` stringe il contesto sul tenant dell'utente e da li' in poi **il database
 * stesso** (RLS + global scope) non restituisce righe altrui. Le stesse identiche classi
 * di risorsa girano anche nel pannello di piattaforma, dove invece vedono tutto — cambia
 * il contesto, non il codice.
 *
 * ⚠️⚠️ `isPersistent: true` SU ResolveTenant. E' la riga piu' importante di questo file.
 *
 * Filament e' fatto di Livewire, e una richiesta Livewire **non ripercorre i middleware
 * della rotta originale**: ripercorre solo quelli dichiarati *persistenti*. Filament
 * persiste `Authenticate` da solo, quindi l'utente resterebbe autenticato — ma senza
 * `ResolveTenant` il contesto resterebbe quello aperto da `EstablishTenantContext`, cioe'
 * **bypass**. E in bypass non c'e' nessun filtro.
 *
 * Concretamente: la pagina si carica giusta, poi al primo click su una colonna per
 * ordinare — che e' una richiesta Livewire — la tabella si ripopola **con gli armadi di
 * tutti i clienti**. Ordinare una tabella non deve far trapelare i dati di un altro locale.
 * C'e' un test che lo verifica (`PanelTest`: "il contesto tenant sopravvive a una richiesta
 * Livewire").
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->brandName('Locker')
            // ⚠️ Login con **username o email**: le persone che usano questo sistema hanno un
            // username e lo sanno a memoria; l'email di servizio dell'account, spesso, no.
            ->login(Login::class)

            // Serve perche' esista una PAGINA su cui atterrare col link di reset:
            // mandare un link che non porta da nessuna parte non e' un reset.
            ->passwordReset()

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
            ->colors(['primary' => Color::Emerald])
            ->pages([Dashboard::class, Registro::class, Mfa::class])
            ->resources([
                CabinetResource::class,
                LockerResource::class,
                SessionResource::class,
                DeviceResource::class,
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

                // ⚠️ Vedi il commento in testa alla classe: senza `isPersistent`, ogni click
                // dentro il pannello girerebbe **senza isolamento tra clienti**.
                ResolveTenant::class,

                // ⚠️ Non impedisce il login: impedisce di *fare cose*. Chi deve il secondo
                // fattore e non ce l'ha viene dirottato sulla pagina che glielo fa configurare.
                EnsureMfaSatisfiedInPanel::class,
            ], isPersistent: true);
    }
}
