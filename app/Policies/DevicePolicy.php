<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

/**
 * I chioschi (FCV5003). Governati da `cabinet.manage`: un chiosco e' parte dell'armadio —
 * lamiera, serrature e uno schermo avvitato in mezzo — non un oggetto a se'.
 *
 * ⚠️ `activate` e' anche il bottone della **ri-abilitazione**: stesso click, nuovo segreto,
 * stesso armadio. Un solo gesto da imparare, perche' un gesto che si impara e' un gesto che
 * si fa; una procedura complicata e' una procedura che il tecnico salta.
 *
 * ⚠️ Un device NON si cancella: si **revoca**. Cancellarlo perderebbe la storia di cosa ha
 * aperto e quando, che e' l'unica cosa che permette di rispondere a "chi ha aperto quel
 * vano?".
 */
final class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cabinet.view');
    }

    public function view(User $user, Device $device): bool
    {
        return $user->can('cabinet.view');
    }

    public function create(User $user): bool
    {
        return $user->can('cabinet.manage');
    }

    public function update(User $user, Device $device): bool
    {
        return $user->can('cabinet.manage');
    }

    /** Apre la finestra di attivazione: il chiosco puo' ritirare le sue credenziali. */
    public function activate(User $user, Device $device): bool
    {
        return $user->can('cabinet.manage');
    }

    /** Il chiosco e' stato rubato, o sostituito: da adesso il broker lo rifiuta. */
    public function revoke(User $user, Device $device): bool
    {
        return $user->can('cabinet.manage');
    }

    public function delete(User $user, Device $device): bool
    {
        // ⚠️ Un chiosco non si cancella: si REVOCA. Cancellarlo perderebbe la storia di cosa
        // ha aperto e quando — cioe' l'unica cosa che permette di rispondere a "chi ha aperto
        // quel vano?".
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
