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
use Laravel\Sanctum\HasApiTokens;

/**
 * Il FCV5003: lo schermo avvitato dentro l'armadio.
 *
 * ⚠️ Fisicamente **e' un oggetto solo** con l'armadio. Nel database sono due tabelle per un
 * motivo solo: **l'elettronica si rompe, la lamiera no.** Quando il FCV5003 fuma e lo si
 * sostituisce, l'armadio — coi suoi vani, le sue sessioni, il suo audit — deve sopravvivere.
 * Fonderli significherebbe perdere quella storia (o mescolarla fra due dispositivi) a ogni RMA.
 *
 * `mqtt_client_id` e' l'identita' con cui si presentera' al broker (F5), e l'ACL per-device
 * costruita su di essa e' il confine tra clienti sul canale realtime (piano §3.3).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $cabinet_id il dispositivo si registra PRIMA dell'armadio
 * @property string $serial
 * @property string $model
 * @property string $mqtt_client_id
 * @property string $status
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $activation_expires_at
 * @property string|null $credentials_payload
 * @property Carbon|null $credentials_delivered_at
 * @property Carbon|null $activated_at
 * @property string|null $signing_secret
 */
class Device extends Model implements TenantScoped
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasUuids;

    protected $fillable = [
        'cabinet_id', 'serial', 'model', 'mqtt_client_id',
        'credential_fingerprint', 'firmware_version', 'ip_address', 'mac_address', 'status',
    ];

    /**
     * C'e' una finestra di attivazione aperta?
     *
     * ⚠️ E' l'unica condizione in cui il server consegna le credenziali. Fuori dalla finestra,
     * chiunque bussi con questo serial — foss'anche il chiosco vero — non ottiene niente:
     * serve che un tecnico prema "Attiva".
     */
    public function hasOpenActivationWindow(): bool
    {
        return $this->activation_expires_at !== null
            && $this->activation_expires_at->isFuture();
    }

    /** Registrato dal tecnico, ma il chiosco non si e' ancora fatto vivo. */
    public function isRegistered(): bool
    {
        return $this->status === 'registered';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

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
            'activation_expires_at' => 'datetime',
            'credentials_delivered_at' => 'datetime',
            'activated_at' => 'datetime',
            'credentials_payload' => 'encrypted',

            // ⚠️ Cifrato a riposo: chi rubasse il database senza APP_KEY non potrebbe firmare
            // comandi validi.
            'signing_secret' => 'encrypted',
        ];
    }
}
