<?php

namespace App\Policies;

use App\Models\Session;
use App\Models\User;

/**
 * Le sessioni (il cappotto di qualcuno dentro un vano).
 *
 * Come per gli armadi: qui NON si controlla il tenant — ci pensano global scope e RLS, e
 * una sessione di un altro locale non arriva nemmeno fin qui. Vedi CabinetPolicy.
 *
 * ⚠️ Non esiste `create`, non esiste `update`, non esiste `delete`. Una sessione nasce
 * quando qualcuno paga e cambia stato solo attraverso `SessionManager`, che e' l'unico
 * posto in cui la macchina a stati e' scritta. Una sessione modificabile a mano dal
 * pannello sarebbe una sessione che puo' finire in uno stato che il codice non prevede —
 * per esempio un vano occupato da una sessione "chiusa", cioe' un cappotto invisibile.
 */
final class SessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('session.view');
    }

    public function view(User $user, Session $session): bool
    {
        return $user->can('session.view');
    }

    /** Avviare la riconsegna: apre il vano e mette la sessione in `checkout`. */
    public function checkout(User $user, Session $session): bool
    {
        return $user->can('session.checkout');
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
        // Una sessione nasce quando qualcuno paga, non quando qualcuno clicca.
        return false;
    }

    public function update(User $user, Session $session): bool
    {
        // ⚠️ Lo stato cambia solo passando da SessionManager, dove la macchina a stati e'
        // scritta una volta sola. Uno `status` editabile a mano e' il modo piu' veloce di
        // ottenere un vano "libero" con dentro la roba di qualcuno.
        return false;
    }

    public function delete(User $user, Session $session): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
