<?php

namespace App\Policies;

use App\Models\User;

/**
 * Gli account.
 *
 * ⚠️ Il tenant, di nuovo, non si controlla qui: un utente di un altro locale e' invisibile
 * a monte (global scope + RLS). Un `tenant_admin` gestisce gli account del proprio locale
 * senza dover sapere che esistono gli altri.
 *
 * ⚠️ **Nessuno puo' cancellare se stesso.** Non e' una gentilezza: un `tenant_admin` che si
 * elimina per sbaglio lascia il locale **senza nessuno che possa creare account** — e a
 * quel punto l'unico rimedio siamo noi, a mano, sul database.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('user.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('user.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('user.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('user.manage');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('user.manage') && $user->id !== $model->id;
    }

    public function deleteAny(User $user): bool
    {
        // Niente cancellazioni di massa di account: il rischio di restare senza nessuno che
        // possa crearne altri non vale la comodita'.
        return false;
    }
}
