<?php

namespace App\Domain\Auth;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * Autenticazione a due fattori (TOTP: i codici a 6 cifre che cambiano ogni 30 secondi).
 *
 * Perche' esiste, in un sistema di armadietti: chi entra nel pannello puo' aprire
 * QUALUNQUE vano del locale. Se qualcuno ruba la password del gestore non porta via dei
 * dati — porta via i cappotti dei clienti, e l'audit log dira' che e' stato il gestore.
 * La password da sola e' un fattore solo: chi la perde, ha perso tutto.
 *
 * E' attivabile e disattivabile dall'amministratore a sistema acceso
 * (PlatformSetting `security.require_mfa`), perche' imporla via variabile d'ambiente
 * avrebbe significato "serve un deploy per cambiarla", e chi gestisce un locale non fa
 * deploy.
 */
final class MfaService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * L'URI che l'app di autenticazione (Google Authenticator, Authy, 1Password…) legge
     * dal QR code.
     */
    public function otpauthUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->username ?? $user->email,
            $secret,
        );
    }

    /**
     * Finestra di 1 intervallo (±30s): gli orologi dei telefoni non sono mai perfetti,
     * e un secondo di deriva non deve tenere fuori il gestore alle 2 di notte.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code, 1) !== false;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return array_map(
            fn (): string => strtoupper(bin2hex(random_bytes(5))),
            range(1, $count),
        );
    }
}
