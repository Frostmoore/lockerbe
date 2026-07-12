<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $session_id
 * @property string $provider
 * @property string|null $provider_ref
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property array<string, mixed> $payload
 * @property Carbon|null $confirmed_at
 */
class Payment extends Model implements TenantScoped
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'session_id', 'provider', 'provider_ref', 'amount_cents', 'currency',
        'status', 'payload',
    ];

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
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
            'amount_cents' => 'integer',
            'payload' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
