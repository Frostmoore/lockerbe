<?php

namespace App\Policies;

use App\Models\Locker;
use App\Models\User;

/**
 * Autorizzazione sui vani.
 *
 * Come CabinetPolicy: il tenant NON si controlla qui (ci pensano scope e RLS, e un vano di
 * un altro tenant non arriva nemmeno a questa classe). Qui si risponde solo a "che cosa puo'
 * fare questo utente".
 */
final class LockerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('locker.view');
    }

    public function view(User $user, Locker $locker): bool
    {
        return $user->can('locker.view');
    }

    /** Apertura del singolo vano. Arriva in F4, ma il permesso esiste gia'. */
    public function open(User $user, Locker $locker): bool
    {
        return $user->can('locker.open');
    }

    /** Mettere/togliere fuori servizio. */
    public function service(User $user, Locker $locker): bool
    {
        return $user->can('locker.service');
    }
}
