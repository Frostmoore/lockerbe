<?php

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: il tenant e' UNO per richiesta (o per job, o per comando). Averne
        // due istanze significherebbe che una parte del codice crede di stare in un
        // tenant e un'altra in un altro — cioe' il bug peggiore possibile qui dentro.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $context = $this->app->make(TenantContext::class);

        // Le variabili di sessione Postgres vivono NELLA connessione: una connessione
        // nuova nasce senza contesto e, con le policy fail-closed, nascerebbe cieca.
        // Riapplichiamo lo stato corrente a ogni connessione che si apre.
        Event::listen(
            ConnectionEstablished::class,
            fn () => $context->syncToDatabase(),
        );

        // Console (migration, tinker, comandi schedulati, test): codice di sistema, non
        // una richiesta di un utente. Nessun filtro tenant, altrimenti una migration non
        // vedrebbe le proprie tabelle e un job non troverebbe i propri record.
        //
        // ⚠️ Il confine e' qui: dal browser si entra SEMPRE attraverso
        // EstablishTenantContext + ResolveTenant, e li' il filtro c'e'. Il codice di
        // console e' fidato per costruzione; quello che arriva dalla rete no.
        if ($this->app->runningInConsole()) {
            $context->bypass();
        }
    }
}
