<?php

namespace App\Domain\Command\Exceptions;

use App\Models\Cabinet;
use RuntimeException;

/**
 * L'armadio non risponde. **Non si accoda niente.**
 *
 * ⚠️ E' la difesa piu' importante del sistema (piano §8.4), e sta tutta in una frase: *un
 * comando di apertura verso un armadio irraggiungibile non si mette in coda per dopo.*
 *
 * La tentazione sarebbe accodarlo — "appena torna online, si apre". Ma "appena torna online"
 * puo' voler dire tre ore dopo, alle 4 del mattino, quando il cliente se n'e' andato da un
 * pezzo e il vano e' pieno della sua roba. L'armadietto si aprirebbe da solo, davanti a
 * nessuno. O davanti a chiunque.
 *
 * Quindi: **409**, e il comando non esiste. Chi lo ha chiesto lo sapra' subito, e potra'
 * riprovare quando l'armadio sara' tornato.
 */
final class DeviceOfflineException extends RuntimeException
{
    public function __construct(public readonly Cabinet $cabinet)
    {
        parent::__construct(
            "L'armadio {$cabinet->code} non risponde: il comando non e' stato accodato. "
            .'Riprova quando sara\' di nuovo online.'
        );
    }
}
