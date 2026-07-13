<?php

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Domain\Tenancy\TenantScoped;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Un account. NON e' il cliente finale del guardaroba.
 *
 * Chi deposita il cappotto **non ha un account** (piano §4: `end_user` "non e' un
 * account"): riapre il proprio vano col tap della carta o con un token di sessione.
 * Qui dentro ci sono solo le persone che *gestiscono* il sistema:
 *
 *   platform_admin  tenant_id NULL  — noi: tutti i tenant, onboarding, OTA
 *   tenant_admin    tenant_id set   — il gestore del locale: i propri armadi, i propri utenti
 *   tenant_staff    tenant_id set   — chi lavora al guardaroba: apre, chiude, checkout
 *
 * `tenant_id` NULL non e' "nessun tenant per ora": significa **platform_admin**. Per
 * questo User non usa il trait BelongsToTenant, che pretende sempre un tenant.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $username
 * @property string $email
 * @property string $status
 * @property Carbon|null $two_factor_confirmed_at
 */
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, TenantScoped
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = ['tenant_id', 'username', 'name', 'email', 'password', 'status'];

    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $user): void {
            // Se stiamo operando dentro un tenant, l'utente nasce in quel tenant e non
            // c'e' modo di sbagliarsi. In bypass (platform_admin che fa onboarding, o
            // codice di sistema) `tenant_id` resta come e' stato passato: NULL vuol dire
            // platform_admin, ed e' una scelta esplicita di chi scrive, non una svista.
            if ($user->tenant_id === null) {
                $user->tenant_id = app(TenantContext::class)->id();
            }
        });
    }

    public function isPlatformAdmin(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * ⚠️ CHI PUO' ENTRARE IN QUALE PANNELLO (F6).
     *
     * Non e' una comodita' di navigazione: e' la porta. Filament chiama questo metodo su
     * **ogni** richiesta del pannello, e un `false` significa 403 — sulla pagina come sulle
     * richieste Livewire che la fanno vivere.
     *
     * ⚠️ Il pannello `admin` gira **in bypass**: un platform_admin ha `tenant_id = NULL`,
     * quindi `ResolveTenant` non stringe niente e il database mostra i dati di *tutti* i
     * clienti. E' voluto — quel pannello serve a questo — ma vuol dire che questa riga e'
     * l'unica cosa che separa un utente di un locale dall'intero parco clienti. Non un
     * pezzo: tutto. C'e' un test (`PanelTest`).
     *
     * Uno `status` diverso da `active` non entra da nessuna parte: sospendere un account
     * deve avere effetto sui pannelli, non solo sulle API.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->isPlatformAdmin() && $this->hasRole('platform_admin'),
            'app' => ! $this->isPlatformAdmin() && $this->hasAnyRole(['tenant_admin', 'tenant_staff']),
            default => false,
        };
    }

    /**
     * Questo utente deve usare la MFA?
     *
     * I platform_admin (noi) seguono l'impostazione di piattaforma. Gli utenti di un
     * locale seguono quella del proprio tenant, che non puo' essere piu' permissiva
     * della piattaforma (vedi Tenant::requiresMfa).
     */
    public function requiresMfa(): bool
    {
        if ($this->isPlatformAdmin()) {
            return PlatformSetting::get('security.require_mfa', false) === true;
        }

        return $this->tenant?->requiresMfa() ?? false;
    }

    public function hasMfaEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
