<?php

namespace App\Domain\Tenancy\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Device;
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
        // ⚠️ Da F5 l'attore autenticato puo' essere un **device**, non solo una persona.
        // Larastan lo deduce dalla config di auth (dove c'e' solo User) e non lo sa: glielo
        // diciamo qui.
        /** @var User|Device|null $attore */
        $attore = $request->user();

        if ($attore instanceof User && $attore->tenant_id !== null) {
            $this->context->setTenant($attore->tenant_id);

            return $next($request);
        }

        /*
         * ⚠️ Un DEVICE autenticato (F5).
         *
         * Il chiosco non e' una persona: non ha ruoli, non ha permessi, non ha una password.
         * Ha un token, e appartiene a un armadio, che appartiene a un locale. Il tenant si
         * ricava da li'.
         *
         * Era previsto dal piano fin da F1 ("ResolveTenant risolve il tenant da: utente
         * Sanctum → device autenticato (F5)"), ed e' cio' che permette al chiosco di creare
         * sessioni per conto del cliente che ha davanti — senza che il cliente abbia un account.
         */
        if ($attore instanceof Device && ! $attore->isRevoked()) {
            $this->context->setTenant($attore->tenant_id);
        }

        return $next($request);
    }
}
