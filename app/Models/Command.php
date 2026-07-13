<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommandFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un ordine verso il device. Nella stragrande maggioranza dei casi: **apri quel vano**.
 *
 * ⚠️ La PK **e'** l'Idempotency-Key (piano §8.5): la genera il client, e un retry con la
 * stessa chiave restituisce lo stesso comando invece di crearne un secondo. Un'apertura in
 * piu' non e' un dettaglio: e' un armadietto aperto due volte.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cabinet_id
 * @property string|null $locker_id
 * @property string|null $session_id
 * @property string $type
 * @property string $reason
 * @property string $status
 * @property array<string, mixed> $payload
 * @property Carbon $issued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $acked_at
 * @property Carbon $expires_at
 * @property int $attempts
 * @property array<string, mixed>|null $result
 * @property string|null $signature
 */
class Command extends Model implements TenantScoped
{
    /** @use HasFactory<CommandFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'id', 'cabinet_id', 'locker_id', 'session_id', 'type', 'reason',
        'payload', 'status', 'issued_by_type', 'issued_by_id',
        'issued_at', 'expires_at', 'signature',
    ];

    /**
     * ⚠️ Il comando e' ancora consegnabile?
     *
     * Un comando scaduto non si esegue **mai**, nemmeno se arriva. E' la difesa contro il
     * rischio #1: un `open` emesso alle 23:00 e recapitato alle 4 del mattino aprirebbe un
     * vano pieno di roba, con nessuno davanti.
     */
    public function isDeliverable(): bool
    {
        return in_array($this->status, ['pending', 'sent'], true)
            && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->expires_at->isPast();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['acked', 'failed', 'expired'], true);
    }

    /** @return BelongsTo<Cabinet, $this> */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /** @return BelongsTo<Locker, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(Locker::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'attempts' => 'integer',
            'issued_at' => 'datetime',
            'sent_at' => 'datetime',
            'acked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
