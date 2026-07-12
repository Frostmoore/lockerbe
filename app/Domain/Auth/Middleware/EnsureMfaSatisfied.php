<?php

namespace App\Domain\Auth\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocca le rotte sensibili se l'utente dovrebbe avere la MFA e non ce l'ha.
 *
 * Non impedisce il login: impedisce di *fare cose*. Chi entra senza secondo fattore, ma
 * dovrebbe averlo, puo' solo arrivare alle rotte di arruolamento (`/auth/mfa/...`) e
 * configurarselo. Tutto il resto — a partire da qualunque cosa apra un vano — risponde
 * 403 finche' non l'ha fatto.
 *
 * L'obbligo e' governato da PlatformSetting `security.require_mfa`, accendibile e
 * spegnibile dall'admin senza deploy. Su un ambiente di sviluppo si tiene spento e si
 * entra con la sola password; in produzione va acceso, ed e' il senso di tutto questo.
 */
final class EnsureMfaSatisfied
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->requiresMfa() && ! $user->hasMfaEnabled()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'mfa_enrollment_required',
                    'message' => 'La verifica in due passaggi e\' obbligatoria per il tuo ruolo. '
                        .'Configurala da /api/v1/auth/mfa/enroll prima di continuare.',
                ],
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
