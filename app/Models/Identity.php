<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cio' che il cliente presenta per riaprire il proprio vano.
 *
 * ⚠️ Il token non e' mai in chiaro: solo `token_hash` (SHA-256). Vale per il database,
 * per i log e per l'audit. Un token di identita' in chiaro e' la chiave di un armadietto.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $session_id
 * @property string $type
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 */
class Identity extends Model implements TenantScoped
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['session_id', 'type', 'token_hash'];

    /** L'unico modo ammesso di trasformare un token in qualcosa da salvare o cercare. */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** @return BelongsTo<Session, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }
}
