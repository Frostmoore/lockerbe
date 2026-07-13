<?php

namespace App\Providers;

use App\Domain\Command\Contracts\CommandDispatcher;
use App\Domain\Command\Services\CommandIssuer;
use App\Domain\Identity\Contracts\IdentityProvider;
use App\Domain\Identity\Providers\MockIdentityProvider;
use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\Providers\MockPaymentProvider;
use App\Domain\Tenancy\TenantContext;
use App\Mqtt\CommandPublisher;
use App\Mqtt\NullCommandPublisher;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
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
         * ⚠️ F4: L'ARMA E' COLLEGATA.
         *
         * Fino a F3 qui c'era RecordingCommandDispatcher, che registrava l'intenzione e non
         * mandava niente a nessuno. Ora c'e' CommandIssuer, che crea comandi veri — con TTL,
         * idempotenza, firma, e il rifiuto verso gli armadi offline.
         *
         * ⚠️ **E' cambiata UNA riga.** SessionManager non se n'e' accorto: e' esattamente il
         * motivo per cui in F3 la firma del contratto era gia' quella definitiva.
         */
        $this->app->bind(CommandDispatcher::class, CommandIssuer::class);

        /*
         * ⚠️ Nei test non si parla col broker.
         *
         * Sarebbero lenti e capricciosi — e un test capriccioso finisce per essere ignorato,
         * che e' il modo peggiore in cui una suite smette di proteggerti. Qui il comando resta
         * `pending`, esattamente come se il broker non avesse risposto: che e' anche lo stato
         * che i test sulla scadenza (§17.2) vogliono osservare.
         *
         * Il publisher vero si verifica **a mano, contro un broker vero**. E' l'unico modo
         * onesto di verificarlo.
         */
        if ($this->app->runningUnitTests()) {
            $this->app->bind(CommandPublisher::class, NullCommandPublisher::class);
        }
    }

    public function boot(): void
    {
        /*
         * ⚠️ IL NOSTRO CSS, E PERCHE' NON SONO CLASSI TAILWIND.
         *
         * Filament spedisce un foglio di stile **gia' compilato**, che contiene soltanto le
         * classi che usa lui. Le utility Tailwind scritte da noi in una Blade (`grid`,
         * `border-2`, `bg-success-50`…) **non ci sono dentro**, e il browser le ignora: le
         * nostre pagine venivano fuori come scritte nude impilate una sull'altra.
         *
         * Per avere Tailwind bisognerebbe compilare un tema Filament con node. Il compromesso
         * scelto e' esplicito: **CSS vero, nessuna toolchain**. Classi prefissate `lk-`.
         *
         * ⚠️ Dopo averlo modificato: `php artisan filament:assets` (lo copia in `public/`).
         */
        FilamentAsset::register([
            Css::make('locker', resource_path('css/locker.css')),
        ]);

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
