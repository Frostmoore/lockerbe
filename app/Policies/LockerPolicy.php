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

    /*
     * ⚠️ I DINIEGHI SI SCRIVONO. Non si lasciano impliciti.
     *
     * Filament, davanti a una policy priva del metodo, di suo **autorizza** — noi abbiamo
     * spento quel default (`strictAuthorization`), ma il modo giusto di dire "no" resta
     * dirlo: un `false` esplicito e' una decisione, un metodo assente e' una dimenticanza,
     * e a distanza di sei mesi non si distinguono.
     */

    public function create(User $user): bool
    {
        // ⚠️ I vani nascono con l'armadio, insieme alla loro mappa RS-485. Un vano in piu' nel
        // database non e' uno sportello in piu' nella lamiera: e' solo un vano che, assegnato
        // a un cliente, non si aprira' mai.
        return false;
    }

    public function update(User $user, Locker $locker): bool
    {
        // Lo stato di un vano lo muovono le sessioni e le azioni di servizio, non un form.
        return false;
    }

    public function delete(User $user, Locker $locker): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
