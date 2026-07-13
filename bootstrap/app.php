<?php

use App\Domain\Audit\Console\VerifyAuditChain;
use App\Domain\Auth\Middleware\EnsureMfaSatisfied;
use App\Domain\Cabinet\Console\MarkOfflineCabinets;
use App\Domain\Device\Exceptions\PairingException;
use App\Domain\Session\Console\CancelExpiredReservations;
use App\Domain\Session\Console\CloseExpiredSessions;
use App\Domain\Session\Console\FinalizePendingCheckouts;
use App\Domain\Session\Exceptions\IllegalTransitionException;
use App\Domain\Session\Exceptions\NoLockerAvailableException;
use App\Domain\Tenancy\Middleware\EstablishTenantContext;
use App\Domain\Tenancy\Middleware\ResolveTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',   // piano §10: la superficie pubblica e' versionata
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // I comandi vivono nel dominio (app/Domain/**/Console), non in app/Console/Commands:
    // la scoperta automatica di Laravel non li vede, quindi si registrano qui.
    ->withCommands([
        VerifyAuditChain::class,
        MarkOfflineCabinets::class,
        CancelExpiredReservations::class,
        CloseExpiredSessions::class,
        FinalizePendingCheckouts::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // In testa a TUTTO: le policy RLS sono fail-closed, quindi senza contesto non si
        // vedrebbe nemmeno la tabella `users` — e nessuno potrebbe autenticarsi. Questo
        // middleware apre la richiesta in modalita' sistema e la chiude ripulendo il
        // contesto. Vedi EstablishTenantContext per il perche' esteso.
        $middleware->prepend(EstablishTenantContext::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,        // va DOPO auth:sanctum
            'mfa' => EnsureMfaSatisfied::class,
        ]);

        /*
         * ⚠️ ORDINE OBBLIGATORIO: ResolveTenant PRIMA di SubstituteBindings.
         *
         * `SubstituteBindings` (il route-model binding: /cabinets/{cabinet} → istanza di
         * Cabinet) vive nel gruppo `api`, che per default gira PRIMA dei middleware di
         * rotta — quindi prima di `tenant`. Risultato: il modello veniva risolto mentre il
         * contesto era ancora in bypass, e un utente poteva caricare l'armadio di un ALTRO
         * locale semplicemente indovinandone l'id. Il global scope non lo proteggeva,
         * perche' al momento della query il filtro non era ancora attivo.
         *
         * Con questa priorita' l'ordine effettivo diventa:
         *     auth:sanctum → tenant (ResolveTenant) → SubstituteBindings → can/Authorize
         * cioe' il modello viene cercato **dentro** il tenant dell'utente, e un armadio
         * altrui semplicemente non esiste: 404.
         *
         * Non e' un dettaglio di stile: e' il confine tra clienti su ogni rotta con un {id}.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envelope d'errore uniforme (piano §10): { "error": { code, message, details } }.
        // Un client che deve distinguere "armadio offline" da "transizione illegale" non
        // puo' farlo leggendo una stringa in italiano: gli serve un `code` stabile.
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            /*
             * Eccezioni di dominio → codici stabili.
             *
             * ⚠️ Nessuna delle due e' un errore del server. "L'armadio e' pieno" e "non puoi
             * fare il checkout di una sessione non pagata" sono RISPOSTE, e il client deve
             * poterle distinguere da un guasto — e fra loro — senza leggere una stringa in
             * italiano.
             */
            if ($e instanceof PairingException) {
                // Codice scaduto, armadio gia' accoppiato, credenziali non ancora pronte:
                // sono risposte, non guasti. Il chiosco deve poterle distinguere senza
                // leggere una stringa in italiano.
                return new JsonResponse([
                    'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
                ], JsonResponse::HTTP_CONFLICT);
            }

            if ($e instanceof NoLockerAvailableException) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'no_locker_available',
                        'message' => $e->getMessage(),
                    ],
                ], JsonResponse::HTTP_CONFLICT);
            }

            if ($e instanceof IllegalTransitionException) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'illegal_transition',
                        'message' => $e->getMessage(),
                        'details' => ['from' => $e->from, 'event' => $e->event],
                    ],
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($e instanceof ValidationException) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => $e->getMessage(),
                        'details' => $e->errors(),
                    ],
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($e instanceof AuthenticationException) {
                return new JsonResponse([
                    'error' => ['code' => 'unauthenticated', 'message' => 'Autenticazione richiesta.'],
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }

            if ($e instanceof AuthorizationException) {
                return new JsonResponse([
                    'error' => ['code' => 'forbidden', 'message' => 'Permesso negato.'],
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            if ($e instanceof HttpExceptionInterface) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'http_error',
                        'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Errore.',
                    ],
                ], $e->getStatusCode());
            }

            return null;
        });
    })->create();
