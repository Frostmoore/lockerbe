<?php

namespace App\Mqtt;

use App\Models\Command;

/**
 * Publisher che non pubblica niente. **Usato SOLO nei test.**
 *
 * ⚠️ I test non devono dipendere da un broker acceso: sarebbero lenti e capricciosi, e un test
 * capriccioso e' un test che prima o poi viene ignorato. Qui il comando resta `pending` —
 * esattamente come se il broker non avesse risposto — che e' anche lo stato che i test §17.2
 * (scadenza) vogliono osservare.
 *
 * Il publisher vero e' verificato **a mano**, contro un broker vero: e' l'unico modo onesto.
 */
final class NullCommandPublisher extends CommandPublisher
{
    public function publish(Command $command): bool
    {
        return false;
    }
}
