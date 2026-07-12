<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\MfaService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Arruolamento e disattivazione del secondo fattore.
 *
 * Queste rotte sono esenti dal middleware EnsureMfaSatisfied, altrimenti chi *deve*
 * configurare la MFA non potrebbe raggiungerle: sarebbe chiuso fuori dall'unica porta
 * che gli permette di rientrare.
 */
final class MfaController
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Genera un segreto e lo restituisce come URI `otpauth://` — quello che sta dentro
     * il QR code che si inquadra con Google Authenticator.
     *
     * Il segreto viene salvato ma la MFA **non e' ancora attiva**: lo diventa solo dopo
     * `confirm`, cioe' dopo che l'utente ha dimostrato di riuscire davvero a generare un
     * codice valido. Attivarla subito significherebbe chiudere fuori chiunque sbagli a
     * inquadrare il QR.
     */
    public function enroll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $secret = $this->mfa->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $this->mfa->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return new JsonResponse([
            'secret' => $secret,
            'otpauth_uri' => $this->mfa->otpauthUri($user, $secret),
            'recovery_codes' => $user->two_factor_recovery_codes,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        /** @var User $user */
        $user = $request->user();

        $secret = $user->two_factor_secret;

        if (! is_string($secret) || ! $this->mfa->verify($secret, $request->string('code')->value())) {
            $this->audit->log('auth.mfa.confirm', [
                'actor' => $user,
                'result' => 'fail',
                'error_code' => 'mfa_code_invalid',
            ]);

            return new JsonResponse([
                'error' => ['code' => 'mfa_code_invalid', 'message' => 'Codice non valido.'],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->audit->log('auth.mfa.enabled', ['actor' => $user]);

        return new JsonResponse(['mfa' => ['enabled' => true]]);
    }

    /**
     * Disattiva il secondo fattore.
     *
     * ⚠️ Se la piattaforma (o il tenant) lo rende obbligatorio, non si puo' disattivare:
     * altrimenti l'obbligo sarebbe una cortesia, e ognuno se lo toglierebbe.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        /** @var User $user */
        $user = $request->user();

        if ($user->requiresMfa()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'mfa_required',
                    'message' => 'La verifica in due passaggi e\' obbligatoria per il tuo ruolo '
                        .'e non puo\' essere disattivata.',
                ],
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        $secret = $user->two_factor_secret;

        if (! is_string($secret) || ! $this->mfa->verify($secret, $request->string('code')->value())) {
            return new JsonResponse([
                'error' => ['code' => 'mfa_code_invalid', 'message' => 'Codice non valido.'],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->log('auth.mfa.disabled', ['actor' => $user]);

        return new JsonResponse(['mfa' => ['enabled' => false]]);
    }
}
