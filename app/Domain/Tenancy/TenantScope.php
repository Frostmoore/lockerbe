<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Primo livello di difesa (piano §3.1): il `WHERE tenant_id` che non ci si puo'
 * dimenticare, perche' non lo scrive nessuno a mano.
 *
 * Il secondo livello e' la RLS nel database (Rls.php). Servono entrambi: questo rende
 * il comportamento corretto e prevedibile nell'uso normale, quella regge quando questo
 * viene aggirato — una query grezza, un `DB::table()`, una relazione caricata storta.
 */
/**
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->hasBypass()) {
            return;
        }

        $tenantId = $context->id();

        if ($tenantId === null) {
            // Nessun tenant e nessun bypass: fail-closed, come la policy RLS.
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = $model instanceof TenantScoped
            ? $model->getTenantColumn()
            : 'tenant_id';

        $builder->where($model->qualifyColumn($column), $tenantId);
    }
}
