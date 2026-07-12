<?php

namespace App\Domain\Session\Exceptions;

use RuntimeException;

/**
 * Una transizione non prevista dalla tabella §7.1 del piano.
 *
 * Esempi reali: checkout su una sessione non ancora pagata; riapertura di una sessione
 * chiusa; pagamento confermato due volte.
 *
 * ⚠️ Non e' un errore del server (500): e' una richiesta illegittima. Diventa **422**.
 * Un sistema che "aggiusta" silenziosamente le transizioni impossibili e' un sistema che
 * un giorno aprira' un vano che avrebbe dovuto restare chiuso.
 */
final class IllegalTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $event,
    ) {
        parent::__construct("Transizione non ammessa: '{$event}' su una sessione '{$from}'.");
    }
}
