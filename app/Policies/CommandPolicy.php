<?php

namespace App\Policies;

use App\Models\Command;
use App\Models\User;

/**
 * I comandi mandati agli armadi. Sola lettura.
 *
 * ⚠️ Un comando non si crea da qui e non si modifica da qui. Nasce da `CommandIssuer`, che
 * e' l'unico posto in cui vivono le tre difese (armadio offline ⇒ niente comando · TTL ·
 * idempotenza) e la firma. Un comando creato a mano dal pannello sarebbe un comando senza
 * scadenza e senza firma: cioe' esattamente l'apertura che il sistema esiste per impedire.
 *
 * Si guardano per capire cos'e' successo: quale vano, quando, se il device ha risposto.
 */
final class CommandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('locker.view');
    }

    public function view(User $user, Command $command): bool
    {
        return $user->can('locker.view');
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
        // ⚠️ Un comando nasce SOLO da CommandIssuer: e' li' che vivono la scadenza, la firma
        // e il rifiuto verso gli armadi offline. Un comando creato a mano sarebbe un comando
        // senza sicure.
        return false;
    }

    public function update(User $user, Command $command): bool
    {
        return false;
    }

    public function delete(User $user, Command $command): bool
    {
        // Cancellare un comando significa cancellare la prova che un vano si e' aperto.
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
