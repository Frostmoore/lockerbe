<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * IL REGISTRO. Si legge e basta.
 *
 * ⚠️ Non c'e' `create`, non c'e' `update`, non c'e' `delete` — e non e' una svista di
 * comodo: il registro e' **append-only imposto dal database** (`REVOKE UPDATE, DELETE` sul
 * ruolo applicativo) e concatenato con un hash. Anche se qualcuno aggiungesse qui un
 * permesso di modifica, Postgres rifiuterebbe la scrittura.
 *
 * L'assenza di questi metodi serve a un'altra cosa: **Filament non deve nemmeno disegnare
 * il bottone**. Un pulsante "elimina" che poi esplode con un errore del database e' peggio
 * di nessun pulsante — insegna che il sistema e' rotto invece che rigoroso.
 */
final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->can('audit.view');
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
        // Il registro si scrive solo attraverso AuditLogger, che tiene integra la hash-chain.
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        // Postgres ha gia' revocato UPDATE a `locker_app`: qui lo diciamo anche noi.
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        // ⚠️ Il punto di tutto il registro e' che NESSUNO possa far sparire cio' che e'
        // successo. Nemmeno noi.
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
