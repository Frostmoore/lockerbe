<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

/**
 * I locali. Solo noi.
 *
 * ⚠️ `tenant.manage` e' un permesso di `platform_admin` e basta (vedi
 * RolesAndPermissionsSeeder). Un `tenant_admin` che potesse creare tenant potrebbe creare
 * **il contenitore in cui vedere qualcosa che non e' suo** — e l'intera separazione tra
 * clienti si regge sul fatto che quel contenitore lo assegniamo noi.
 *
 * ⚠️ Un tenant non si cancella dal pannello. Cancellarlo significherebbe trascinarsi dietro
 * armadi, sessioni e — soprattutto — l'audit, che e' append-only proprio perche' nessuno
 * possa far sparire cio' che e' successo. Un locale che smette si **sospende**.
 */
final class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tenant.manage');
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->can('tenant.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('tenant.manage');
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->can('tenant.manage');
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        // ⚠️ Un locale non si cancella: si SOSPENDE. Cancellarlo si porterebbe dietro armadi,
        // sessioni e soprattutto l'audit — che e' append-only proprio perche' nessuno possa
        // far sparire quello che e' successo.
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
