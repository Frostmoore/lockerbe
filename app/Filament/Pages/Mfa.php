<?php

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\MfaService;
use App\Models\User;
use BackedEnum;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La pagina che fa arruolare il secondo fattore, dentro il pannello (F6).
 *
 * ⚠️ Il segreto viene salvato **ma la MFA non e' ancora attiva**: lo diventa solo dopo che
 * l'utente ha dimostrato di riuscire davvero a produrre un codice valido. Attivarla subito
 * chiuderebbe fuori chiunque sbagli a inquadrare il QR — e chiuderlo fuori dal pannello
 * significa chiuderlo fuori dall'unico posto da cui potrebbe rimediare.
 *
 * ⚠️ Non e' nascosta nel menu quando l'obbligo e' spento: la MFA si puo' attivare anche per
 * scelta. Ma quando l'obbligo e' acceso e l'utente non ce l'ha, `EnsureMfaSatisfiedInPanel`
 * lo porta qui e non lo lascia andare da nessun'altra parte.
 *
 * Il QR lo genera il server (endroid, data-URI): la stessa scelta fatta per il chiosco, e
 * per lo stesso motivo — chi guarda deve solo mostrare un'immagine.
 */
class Mfa extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $slug = 'mfa';

    protected static ?string $navigationLabel = 'Verifica in due passaggi';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.mfa';

    public string $code = '';

    public ?string $secret = null;

    public ?string $qr = null;

    public bool $attiva = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->attiva = $user->hasMfaEnabled();

        if ($this->attiva) {
            return;
        }

        $mfa = app(MfaService::class);
        $this->secret = $mfa->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $this->secret,
            'two_factor_recovery_codes' => $mfa->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        // Il QR lo genera il server (data-URI): la stessa scelta fatta per il chiosco, e per
        // lo stesso motivo — chi guarda deve solo mostrare un'immagine.
        $this->qr = (new PngWriter)
            ->write(new QrCode($mfa->otpauthUri($user, $this->secret)))
            ->getDataUri();
    }

    public function conferma(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $segreto = $user->two_factor_secret;

        if (! is_string($segreto) || ! app(MfaService::class)->verify($segreto, $this->code)) {
            app(AuditLogger::class)->log('auth.mfa.confirm', [
                'actor' => $user,
                'result' => 'fail',
                'error_code' => 'mfa_code_invalid',
            ]);

            Notification::make()
                ->title('Codice non valido.')
                ->body('Controlla che l\'orologio del telefono sia sincronizzato.')
                ->danger()
                ->send();

            return;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        app(AuditLogger::class)->log('auth.mfa.enabled', ['actor' => $user]);

        $this->attiva = true;
        $this->code = '';

        Notification::make()->title('Verifica in due passaggi attiva.')->success()->send();

        $this->redirect(filament()->getUrl());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Verifica in due passaggi';
    }

    /** Il pannello richiede la MFA a questo utente? Serve alla vista per il tono del messaggio. */
    public function obbligatoria(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->requiresMfa();
    }
}
