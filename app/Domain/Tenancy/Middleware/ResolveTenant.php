<?php

namespace App\Domain\Tenancy\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chiude la finestra aperta da EstablishTenantContext: dall'utente autenticato ricava il
 * tenant, e da qui in poi l'isolamento e' armato.
 *
 * Va montata **dopo** `auth:sanctum`, mai prima: prima dell'autenticazione non c'e'
 * nessun utente da cui ricavare il tenant.
 *
 * Un `platform_admin` (tenant_id NULL) resta in bypass: e' il suo mestiere vedere tutti
 * i tenant. Non e' una svista, ed e' l'unico caso in cui un essere umano autenticato
 * opera senza filtro.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->tenant_id !== null) {
            $this->context->setTenant($user->tenant_id);
        }

        return $next($request);
    }
}
