<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CabinetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Un Armadio: un FCV5003 e i suoi vani.
 *
 * Separato da Device di proposito (piano §2): quando il dispositivo si rompe, l'armadio
 * e la sua storia sopravvivono e gli si associa un dispositivo nuovo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $site_id
 * @property string $name
 * @property string $code
 * @property string $status
 * @property string|null $firmware_version
 * @property Carbon|null $last_seen_at
 * @property array<string, mixed> $settings
 */
class Cabinet extends Model implements TenantScoped
{
    /** @use HasFactory<CabinetFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['site_id', 'name', 'code', 'status', 'settings'];

    /**
     * L'armadio ha dato segni di vita di recente?
     *
     * ⚠️ Questa e' la domanda da cui dipende il rischio #1 del sistema (piano §8). Un
     * comando di apertura verso un armadio offline NON si accoda: verrebbe consegnato ore
     * dopo, aprendo un vano pieno di roba nel cuore della notte. Da F4, `POST /open` su un
     * cabinet offline risponde 409 e non crea nulla.
     *
     * `maintenance` e' sempre "non raggiungibile", qualunque cosa dica l'heartbeat: se un
     * tecnico ci sta lavorando, non gli si aprono i vani in faccia.
     */
    public function isOnline(): bool
    {
        if ($this->status === 'maintenance' || $this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->gt(
            now()->subSeconds((int) config('locker.heartbeat.timeout'))
        );
    }

    /** @return HasOne<Device, $this> */
    public function device(): HasOne
    {
        return $this->hasOne(Device::class);
    }

    /** @return HasMany<Locker, $this> */
    public function lockers(): HasMany
    {
        return $this->hasMany(Locker::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }
}
