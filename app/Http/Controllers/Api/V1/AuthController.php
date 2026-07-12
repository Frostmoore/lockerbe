<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\MfaService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Login con **username o email**, piu' il codice a 6 cifre se la MFA e' attiva.
     *
     * La ricerca dell'utente gira in bypass di proposito: in questo istante non sappiamo
     * ancora a che tenant appartiene chi sta bussando — e' proprio quello che stiamo
     * cercando di scoprire. Subito dopo il contesto viene stretto sul suo tenant.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'size:6'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        /** @var User|null $user */
        $user = $this->context->runWithBypass(
            fn (): ?User => User::query()
                ->where('username', $credentials['login'])
                ->orWhere('email', $credentials['login'])
                ->first(),
        );

        // Messaggio unico e deliberatamente vago: distinguere "utente inesistente" da
        // "password sbagliata" regalerebbe a chiunque un modo per scoprire quali account
        // esistono.
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $this->audit->log('auth.login', [
                'actor' => $user,
                'result' => 'fail',
                'error_code' => 'invalid_credentials',
                'context' => ['login' => $credentials['login']],
            ]);

            throw ValidationException::withMessages([
                'login' => ['Credenziali non valide.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'login' => ['Account disabilitato.'],
            ]);
        }

        if ($user->hasMfaEnabled()) {
            $code = $credentials['code'] ?? null;

            if ($code === null || ! $this->mfa->verify((string) $user->two_factor_secret, $code)) {
                $this->audit->log('auth.login', [
                    'actor' => $user,
                    'result' => 'fail',
                    'error_code' => $code === null ? 'mfa_code_required' : 'mfa_code_invalid',
                ]);

                return new JsonResponse([
                    'error' => [
                        'code' => $code === null ? 'mfa_code_required' : 'mfa_code_invalid',
                        'message' => 'Serve il codice della verifica in due passaggi.',
                    ],
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }
        }

        if ($user->tenant_id !== null) {
            $this->context->setTenant($user->tenant_id);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $token = $user->createToken($credentials['device_name'] ?? 'panel')->plainTextToken;

        $this->audit->log('auth.login', ['actor' => $user]);

        return new JsonResponse([
            'token' => $token,
            'user' => $this->profile($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->currentAccessToken()->delete();

        $this->audit->log('auth.logout', ['actor' => $user]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['user' => $this->profile($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'is_platform_admin' => $user->isPlatformAdmin(),
            'roles' => $user->getRoleNames()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->all(),
            'mfa' => [
                'enabled' => $user->hasMfaEnabled(),
                'required' => $user->requiresMfa(),
            ],
        ];
    }
}
