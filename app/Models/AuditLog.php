<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use App\Domain\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sola lettura, dal punto di vista dell'applicazione.
 *
 * Si scrive **solo** attraverso AuditLogger, mai con `AuditLog::create()`: e' l'unico
 * modo di garantire che la hash-chain resti integra. Il database, per parte sua, ha gia'
 * revocato UPDATE e DELETE a `locker_app` — quindi anche volendo non si puo' riscrivere
 * la storia.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $action
 * @property string $hash
 * @property string|null $prev_hash
 * @property string $actor_type 'user' | 'device' | 'system' | 'webhook'
 * @property string|null $actor_id
 * @property string|null $actor_role
 * @property string $result 'ok' | 'fail'
 * @property string|null $error_code
 * @property string|null $ip
 * @property string|null $cabinet_id
 * @property string|null $locker_id
 * @property string|null $session_id
 * @property string|null $command_id
 * @property array<string, mixed> $context
 * @property Carbon|null $created_at
 */
class AuditLog extends Model implements TenantScoped
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * ⚠️ Relazione di sola lettura, per **mostrare** una voce: "vano 3" si legge, un uuid no.
     *
     * ⚠️ Il vano potrebbe non esistere più (`nullOnDelete` non c'è: l'audit tiene l'uuid anche
     * se il vano sparisce — è append-only, non insegue le cancellazioni). Quindi è nullable, e
     * la vista deve reggere il `null`. Un registro che si rompe perché qualcuno ha smontato un
     * armadio è un registro che non serve proprio quando serve.
     *
     * @return BelongsTo<Locker, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(Locker::class);
    }

    /**
     * Il comando dietro questa voce, se ce n'è uno.
     *
     * ⚠️ **`null` non vuol dire "non lo so": vuol dire NESSUNO L'HA ORDINATO.** Su un
     * `locker.opened`, l'assenza di comando è il fatto interessante — è un vano aperto a mano.
     *
     * @return BelongsTo<Command, $this>
     */
    public function command(): BelongsTo
    {
        return $this->belongsTo(Command::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
