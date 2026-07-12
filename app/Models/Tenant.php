<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use App\Domain\Tenancy\TenantScoped;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un cliente: tipicamente un locale, con i suoi armadi e il suo pannello.
 *
 * Nota: Tenant e' tenant-scoped **su se stesso**. Un utente di un locale, guardando la
 * tabella dei tenant, deve vedere una riga sola — la propria. Da qui `getTenantColumn()`
 * che restituisce `id` e non `tenant_id`.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $timezone
 * @property array<string, mixed> $settings
 */
class Tenant extends Model implements TenantScoped
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'status', 'timezone', 'settings'];

    public function getTenantColumn(): string
    {
        return 'id';
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * La MFA e' obbligatoria per gli utenti di questo tenant?
     *
     * L'impostazione di piattaforma e' il default; il singolo locale puo' alzare l'asticella
     * ma — deliberatamente — **non abbassarla**: se la piattaforma la impone, un tenant non
     * puo' disattivarla per conto suo. Chi entra nel pannello puo' aprire ogni armadietto
     * del locale, e quella scelta non puo' restare nelle mani di chi ha la password piu'
     * debole.
     */
    public function requiresMfa(): bool
    {
        if (PlatformSetting::get('security.require_mfa', false) === true) {
            return true;
        }

        return (bool) ($this->settings['require_mfa'] ?? false);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
