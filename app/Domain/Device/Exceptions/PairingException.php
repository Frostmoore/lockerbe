<?php

namespace App\Domain\Device\Exceptions;

use RuntimeException;

/**
 * Qualcosa e' andato storto nell'accoppiamento di un chiosco.
 *
 * Non e' un errore del server: e' una risposta. Il codice e' scaduto, l'armadio ha gia' un
 * chiosco, il dispositivo non e' ancora stato accoppiato. Il client — che qui e' un
 * FCV5003 con uno schermo davanti a un tecnico — deve poter distinguere i casi senza
 * leggere una stringa in italiano.
 */
final class PairingException extends RuntimeException
{
    /**
     * `$errorCode` e non `$code`: quest'ultimo esiste gia' su Exception e non e' readonly,
     * quindi ridichiararlo e' un errore fatale di PHP.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
