<?php

namespace App\Policies;

use App\Models\Cabinet;
use App\Models\User;

/**
 * Autorizzazione sugli armadi.
 *
 * ⚠️ Qui NON si controlla il tenant. Sembra una dimenticanza e non lo e': un armadio di un
 * altro tenant **non arriva nemmeno fin qui**, perche' il global scope e la RLS lo hanno
 * gia' reso invisibile — il route-model binding produce un 404 prima che la policy giri.
 * Rifare il controllo qui darebbe l'illusione che sia questo a proteggerci, e il giorno che
 * qualcuno lo togliesse "perche' ridondante" nessuno saprebbe piu' dove guarda la difesa
 * vera.
 *
 * Le policy qui rispondono a una domanda diversa: *questo utente, nel SUO tenant, ha il
 * permesso di fare questa cosa?* (piano §4)
 */
final class CabinetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cabinet.view');
    }

    public function view(User $user, Cabinet $cabinet): bool
    {
        return $user->can('cabinet.view');
    }

    public function create(User $user): bool
    {
        return $user->can('cabinet.manage');
    }

    public function update(User $user, Cabinet $cabinet): bool
    {
        return $user->can('cabinet.manage');
    }

    public function delete(User $user, Cabinet $cabinet): bool
    {
        return $user->can('cabinet.manage');
    }

    /**
     * Aprire TUTTI i vani di un armadio in un colpo solo.
     *
     * ⚠️ E' l'azione piu' pericolosa del sistema: svuota il guardaroba. Non e' di
     * `tenant_staff` — resta al gestore, che ne risponde. Da F4 richiede anche conferma
     * esplicita e motivazione, e finisce nell'audit.
     */
    public function openAll(User $user, Cabinet $cabinet): bool
    {
        return $user->can('locker.open_all');
    }
}
