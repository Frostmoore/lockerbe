<?php

namespace App\Domain\Auth\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La MFA nei pannelli (F6). Stessa regola di `EnsureMfaSatisfied`, altro modo di dirla.
 *
 * ⚠️ Perche' non riusare quello delle API: `EnsureMfaSatisfied` risponde **403 JSON**. In
 * un pannello web quel 403 sarebbe una pagina bianca con un errore, e l'utente non avrebbe
 * nessun modo di capire che gli manca il secondo fattore — ne' dove andarselo a configurare.
 * Qui lo si accompagna alla pagina che glielo fa attivare.
 *
 * ⚠️ Non impedisce il **login**: impedisce di *fare cose*. Chi entra senza secondo fattore,
 * ma dovrebbe averlo, puo' raggiungere solo la pagina MFA e il logout. Bloccare il login
 * invece che le azioni chiuderebbe fuori chiunque debba ancora arruolarsi — cioe' tutti, il
 * giorno in cui l'obbligo viene acceso.
 *
 * L'obbligo si accende e si spegne da `PlatformSetting security.require_mfa`, senza deploy.
 */
final class EnsureMfaSatisfiedInPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->requiresMfa() || $user->hasMfaEnabled()) {
            return $next($request);
        }

        // ⚠️ Le uniche due strade lasciate aperte: configurare la MFA, o andarsene. Senza
        // la prima, l'utente sarebbe in un vicolo cieco; senza la seconda, non potrebbe
        // nemmeno uscire.
        if ($request->routeIs('filament.*.pages.mfa', 'filament.*.auth.logout')) {
            return $next($request);
        }

        $panel = Filament::getCurrentPanel();

        if ($panel === null) {
            return $next($request);
        }

        return redirect()->route('filament.'.$panel->getId().'.pages.mfa');
    }
}
