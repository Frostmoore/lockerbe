<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Il rapporto fra un cliente e un vano: paga, deposita, riapre, fa checkout.
 *
 * ⚠️ Non e' la sessione HTTP. Qui dentro c'e' il cappotto di qualcuno.
 *
 * Stati (piano §7.1):
 *   created   → prenotato, in attesa di pagamento (scade a `reserved_until`)
 *   active    → pagato, vano occupato. Riapribile.
 *   closed    → checkout fatto (o fine serata forzata)
 *   cancelled → pagamento fallito o prenotazione scaduta
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cabinet_id
 * @property string $locker_id
 * @property string $status
 * @property int $amount_cents
 * @property string $currency
 * @property string $payment_method 'qr' | 'nfc'
 * @property string|null $customer_email
 * @property string|null $payment_id
 * @property string|null $public_token_hash
 * @property Carbon $reserved_until
 * @property Carbon|null $expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $checkout_pending_at
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property int $reopen_count
 * @property array<string, mixed> $meta
 */
class Session extends Model implements TenantScoped
{
    /** @use HasFactory<SessionFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'cabinet_id', 'locker_id', 'status', 'amount_cents', 'currency',
        // ⚠️ Come ha pagato decide TUTTO il flusso di identita': QR ⇒ codice a 6 cifre per
        // email, NFC ⇒ token di carta dal provider. Dimenticarlo qui significa che il metodo
        // scelto dal cliente viene scartato in silenzio da Eloquent, e tutti tornano a 'qr'.
        'payment_method', 'customer_email',
        'payment_id', 'public_token_hash', 'reserved_until', 'expires_at', 'meta',
    ];

    /** Riconsegna dichiarata dal cliente, non ancora confermata: il vano e' aperto ma resta suo. */
    public function isCheckoutPending(): bool
    {
        return $this->checkout_pending_at !== null;
    }

    /** Gli stati da cui la sessione puo' ancora muoversi. */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['closed', 'cancelled'], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Locker, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(Locker::class);
    }

    /** @return BelongsTo<Cabinet, $this> */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return HasMany<Identity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'reopen_count' => 'integer',
            'meta' => 'array',
            'reserved_until' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'checkout_pending_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
