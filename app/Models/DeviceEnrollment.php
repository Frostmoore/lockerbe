<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un chiosco che si e' presentato ma non appartiene ancora a nessuno.
 *
 * ⚠️ **Non e' tenant-scoped e non ha RLS**, e non e' una svista: un FCV5003 appena tolto
 * dalla scatola non ha un tenant — non si sa ancora di quale locale sara'. Qui la mancanza
 * di tenant e' lo stato normale.
 *
 * @property string $id
 * @property string $serial
 * @property string|null $pairing_code
 * @property Carbon|null $pairing_code_expires_at
 * @property string $status
 * @property string|null $credentials_payload
 * @property Carbon|null $credentials_delivered_at
 * @property string|null $device_id
 */
class DeviceEnrollment extends Model
{
    use HasUuids;

    protected $fillable = [
        'serial', 'model', 'mac_address', 'ip_address',
        'pairing_code', 'pairing_code_expires_at', 'status',
        'credentials_payload', 'credentials_delivered_at', 'device_id',
    ];

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function hasValidPairingCode(): bool
    {
        return $this->pairing_code !== null
            && $this->pairing_code_expires_at !== null
            && $this->pairing_code_expires_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pairing_code_expires_at' => 'datetime',
            'credentials_delivered_at' => 'datetime',
            'credentials_payload' => 'encrypted',
        ];
    }
}
