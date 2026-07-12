<?php

use App\Domain\Audit\Console\VerifyAuditChain;
use App\Domain\Auth\Middleware\EnsureMfaSatisfied;
use App\Domain\Tenancy\Middleware\EstablishTenantContext;
use App\Domain\Tenancy\Middleware\ResolveTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ->withCommands([VerifyAuditChain::class])
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envelope d'errore uniforme (piano §10): { "error": { code, message, details } }.
        // Un client che deve distinguere "armadio offline" da "transizione illegale" non
        // puo' farlo leggendo una stringa in italiano: gli serve un `code` stabile.
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
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
