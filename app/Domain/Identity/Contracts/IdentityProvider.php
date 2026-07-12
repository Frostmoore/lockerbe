<?php

namespace App\Domain\Identity\Contracts;

use App\Models\Cabinet;
use App\Models\Identity;
use App\Models\Session;

/**
 * Il confine fra il dominio e "come il cliente dimostra di essere lui".
 *
 * Oggi: un bottone (carta mock). Domani: una card NFC letta dal FCV5003, oppure un token
 * web — dipende da D2, ancora aperta.
 *
 * ⚠️ La code-path e' **identica** nei due casi: cambia solo chi produce il token grezzo.
 * Se un giorno l'NFC richiedesse di toccare SessionManager, vorrebbe dire che questo
 * contratto era sbagliato.
 */
interface IdentityProvider
{
    /** Il token presentato appartiene a una sessione attiva di questo armadio? */
    public function resolve(string $rawToken, Cabinet $cabinet): ?Session;

    /** Lega il token alla sessione: e' la "registrazione" della carta al primo uso. */
    public function bind(Session $session, string $rawToken): Identity;
}
