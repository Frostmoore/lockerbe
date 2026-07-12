<?php

namespace App\Domain\Session\Exceptions;

use App\Models\Cabinet;
use RuntimeException;

/**
 * L'armadio e' pieno. Non e' un guasto: e' una risposta. Diventa 409.
 */
final class NoLockerAvailableException extends RuntimeException
{
    public function __construct(public readonly Cabinet $cabinet)
    {
        parent::__construct("Nessun vano libero nell'armadio {$cabinet->code}.");
    }
}
