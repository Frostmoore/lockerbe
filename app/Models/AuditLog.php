<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use App\Domain\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Model;

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
