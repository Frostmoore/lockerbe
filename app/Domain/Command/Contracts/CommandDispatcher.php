<?php

namespace App\Domain\Command\Contracts;

use App\Models\Locker;

/**
 * Chi manda al device l'ordine di aprire un vano.
 *
 * ⚠️ Questa firma e' GIA' QUELLA DEFINITIVA, anche se in F3 dietro c'e' solo un finto
 * dispatcher. E' voluto: `SessionManager` chiama gia' il metodo che chiamera' per sempre,
 * e in F4 cambia soltanto l'implementazione registrata nel container. Se avessimo inventato
 * una firma "provvisoria", in F4 avremmo dovuto riscrivere SessionManager — cioe' la classe
 * piu' delicata del sistema, quella che decide quando un armadietto si apre.
 *
 * In F4 l'implementazione vera aggiunge le cose che qui non ci sono ancora:
 *   - `expires_at` su ogni comando (TTL: default 30s)
 *   - rifiuto se il cabinet e' offline (409, invece di accodare una promessa di apertura)
 *   - firma HMAC, idempotenza, registrazione in tabella `commands`
 */
interface CommandDispatcher
{
    /**
     * Emette un comando di apertura per il vano.
     *
     * @param  'store'|'reopen'|'checkout'|'admin'|'maintenance'  $reason
     * @return string id del comando (uuid), tracciabile da `GET /commands/{id}` in F4
     */
    public function issueOpen(Locker $locker, string $reason): string;
}
