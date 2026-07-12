<?php

namespace App\Providers;

use App\Domain\Command\Contracts\CommandDispatcher;
use App\Domain\Command\Dispatchers\RecordingCommandDispatcher;
use App\Domain\Identity\Contracts\IdentityProvider;
use App\Domain\Identity\Providers\MockIdentityProvider;
use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\Providers\MockPaymentProvider;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: il tenant e' UNO per richiesta (o per job, o per comando). Averne
        // due istanze significherebbe che una parte del codice crede di stare in un
        // tenant e un'altra in un altro — cioe' il bug peggiore possibile qui dentro.
        $this->app->singleton(TenantContext::class);

        /*
         * Pagamento e identita': quali implementazioni girano davvero.
         *
         * ⚠️ Questo e' il punto in cui D1 (Nexi) e D2 (NFC) si sbloccheranno **senza
         * toccare il resto del sistema**: si implementa il contratto, si cambia una riga
         * nel `.env`, e nient'altro si muove. Se un giorno servisse modificare
         * SessionManager per far entrare Nexi, vorrebbe dire che i contratti erano
         * sbagliati — e sarebbe il momento di fermarsi, non di aggiungere un `if`.
         */
        $this->app->bind(PaymentProvider::class, fn (): PaymentProvider => match (config('locker.payment.driver')) {
            'mock' => new MockPaymentProvider,
            default => throw new InvalidArgumentException(
                'Driver di pagamento sconosciuto: '.(string) config('locker.payment.driver')
                .'. Nexi arriva in F8 (D1 ancora aperta col cliente).'
            ),
        });

        $this->app->bind(IdentityProvider::class, fn (): IdentityProvider => match (config('locker.identity.driver')) {
            'mock' => new MockIdentityProvider,
            default => throw new InvalidArgumentException(
                'Driver di identita\' sconosciuto: '.(string) config('locker.identity.driver')
                .'. NFC arriva in FH (serve l\'hardware, e D2/D3 sono aperte).'
            ),
        });

        /*
         * ⚠️ In F3 NESSUN comando parte davvero: RecordingCommandDispatcher registra
         * l'intenzione e basta. Le difese (TTL, idempotenza, rifiuto verso gli armadi
         * offline) arrivano in F4 — e finche' non ci sono, non si collega l'arma.
         *
         * In F4 si cambia SOLO questa riga: SessionManager non se ne accorgera'.
         */
        $this->app->bind(CommandDispatcher::class, RecordingCommandDispatcher::class);
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
