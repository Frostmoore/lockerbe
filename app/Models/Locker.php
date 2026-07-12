<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LockerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un vano. E' l'oggetto che si apre, quindi e' l'oggetto che puo' fare danni.
 *
 * Stati (piano §7.2): free → reserved → occupied → checkout → free.
 * `out_of_service` si entra e si esce da qualunque stato (solo staff/admin) ed e'
 * ⚠️ **escluso dall'assegnazione automatica**: un vano rotto non deve essere assegnato a
 * un cliente che poi non riesce a riaprirlo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cabinet_id
 * @property int $number
 * @property int $board_address
 * @property int $channel
 * @property string $status
 * @property string|null $current_session_id
 * @property Carbon|null $last_opened_at
 */
class Locker extends Model implements TenantScoped
{
    /** @use HasFactory<LockerFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    // ⚠️ `current_session_id` e `last_opened_at` DEVONO essere qui: senza, l'assegnazione
    // mass-assignment li scarta in silenzio e il legame vano↔sessione non viene mai
    // salvato. Il sistema sembra funzionare — la sessione esiste, il vano risulta occupato —
    // ma nessuno sa piu' a quale sessione appartenga quel vano.
    protected $fillable = [
        'cabinet_id', 'number', 'board_address', 'channel', 'status',
        'current_session_id', 'last_opened_at',
    ];

    /**
     * Gli stati da cui una sessione puo' nascere. Usato dall'assegnazione "primo libero"
     * (F3, piano §11.1), che dovra' aggiungerci `lockForUpdate()`: senza, due richieste
     * simultanee prenderebbero lo stesso vano.
     *
     * @param  Builder<Locker>  $query
     * @return Builder<Locker>
     */
    public function scopeFreeInCabinet(Builder $query, string $cabinetId): Builder
    {
        return $query->where('cabinet_id', $cabinetId)
            ->where('status', 'free')      // esclude out_of_service per costruzione
            ->orderBy('number');
    }

    public function isOutOfService(): bool
    {
        return $this->status === 'out_of_service';
    }

    /** Puo' essere assegnato a un cliente che chiede un vano? (F3) */
    public function isAssignable(): bool
    {
        return $this->status === 'free';
    }

    /**
     * L'indirizzo fisico sulla scheda serrature RS-485: quello che finira' nel payload del
     * comando verso il device (piano §9).
     *
     * @return array{number: int, board: int, channel: int}
     */
    public function physicalAddress(): array
    {
        return [
            'number' => $this->number,
            'board' => $this->board_address,
            'channel' => $this->channel,
        ];
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
            'number' => 'integer',
            'board_address' => 'integer',
            'channel' => 'integer',
            'last_opened_at' => 'datetime',
        ];
    }
}
