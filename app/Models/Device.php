<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Il FCV5003 fisico. 1:1 col Cabinet.
 *
 * `mqtt_client_id` e' l'identita' con cui si presentera' al broker (F5), e l'ACL per-device
 * costruita su di essa e' il confine tra clienti sul canale realtime (piano §3.3).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cabinet_id
 * @property string $serial
 * @property string $model
 * @property string $mqtt_client_id
 * @property string $status
 * @property Carbon|null $last_seen_at
 */
class Device extends Model implements TenantScoped
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'cabinet_id', 'serial', 'model', 'mqtt_client_id',
        'credential_fingerprint', 'firmware_version', 'ip_address', 'mac_address', 'status',
    ];

    /** @return BelongsTo<Cabinet, $this> */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
