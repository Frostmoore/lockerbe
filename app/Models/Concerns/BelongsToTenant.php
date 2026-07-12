<?php

namespace App\Models\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Da usare su OGNI modello tenant-scoped (piano §3.1).
 *
 * Fa due cose:
 *   - in lettura, applica il TenantScope;
 *   - in scrittura, riempie `tenant_id` dal contesto.
 *
 * Sul secondo punto c'e' una scelta che vale la pena spiegare: se il contesto non c'e',
 * il modello **non viene salvato** — si solleva un'eccezione. La tentazione sarebbe
 * scrivere `tenant_id = null` e andare avanti; il risultato pero' sarebbe una riga
 * orfana che nessuna policy RLS puo' proteggere, perche' non appartiene a nessuno.
 * Meglio un errore rumoroso in sviluppo che un vano invisibile in produzione.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute($model->getTenantColumn()) !== null) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                throw new RuntimeException(sprintf(
                    'Tentativo di creare un %s senza tenant nel contesto. Un record '
                    .'senza tenant_id non appartiene a nessuno e nessuna policy RLS puo\' '
                    .'proteggerlo: imposta il contesto (TenantContext) o passa tenant_id '
                    .'esplicitamente.',
                    static::class,
                ));
            }

            $model->setAttribute($model->getTenantColumn(), $tenantId);
        });
    }

    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
