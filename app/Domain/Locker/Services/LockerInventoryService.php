<?php

namespace App\Domain\Locker\Services;

use App\Domain\Session\Exceptions\NoLockerAvailableException;
use App\Models\Cabinet;
use App\Models\Locker;
use Illuminate\Support\Facades\DB;

/**
 * Assegnazione automatica del vano: il primo libero, per numero.
 */
final class LockerInventoryService
{
    /**
     * ⚠️ QUI VIVE LA RACE PIU' PERICOLOSA DEL SISTEMA.
     *
     * Due persone che premono "voglio un vano" nello stesso istante sullo stesso armadio.
     * Senza `lockForUpdate()`, entrambe leggono lo stesso "primo vano libero", entrambe
     * pagano, e la seconda apre l'armadietto che contiene il cappotto della prima.
     *
     * `lockForUpdate()` serializza le due transazioni: la seconda si blocca sulla riga, e
     * quando riparte Postgres rivaluta il `WHERE status = 'free'` sulla versione aggiornata
     * — il vano ora e' `reserved`, non corrisponde piu', e passa al successivo.
     *
     * Non basta da solo: la seconda meta' della difesa e' l'indice unico parziale
     * `one_active_session_per_locker` (migration `sessions`). Il lock protegge il percorso
     * normale; l'indice protegge da tutto il resto — un bug, una scrittura a mano, un
     * percorso che qualcuno aggiungera' fra due anni senza sapere di questa riga.
     *
     * @throws NoLockerAvailableException
     */
    public function assignFirstFree(Cabinet $cabinet): Locker
    {
        return DB::transaction(function () use ($cabinet): Locker {
            $locker = Locker::query()
                ->freeInCabinet($cabinet->id)   // status = free, ordinati per numero
                ->lockForUpdate()
                ->first();

            if ($locker === null) {
                // Nessun vano libero non e' un errore del server: e' una risposta legittima
                // ("l'armadio e' pieno"). Diventa 409, non 500.
                throw new NoLockerAvailableException($cabinet);
            }

            $locker->update(['status' => 'reserved']);

            return $locker;
        });
    }
}
