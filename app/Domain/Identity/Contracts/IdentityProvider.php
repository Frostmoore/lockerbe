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

    /**
     * ⚠️ QUESTA CARTA TIENE GIA' UN VANO?
     *
     * Una carta puo' essere legata **al piu' a una sessione attiva alla volta**. Non e' una
     * regola di comodo:
     *
     *  - se una carta aprisse due vani, **chi la trovasse per terra avrebbe le chiavi di
     *    entrambi**;
     *  - e il sistema non saprebbe **quale** dei due aprire al tap. La logica attuale prende
     *    la sessione piu' recente — cioe' il primo vano diventerebbe **irraggiungibile con la
     *    propria carta**: il cliente ha pagato, il cappotto e' dentro, e solo lo staff puo'
     *    tirarlo fuori.
     *
     * Il vincolo **non e' esprimibile con un indice unico** (dipende dallo stato di un'altra
     * tabella: vedi la migration `identities`), quindi vive nel codice — ed e' per questo che
     * sta nel contratto e non nascosto in un'implementazione: chi scrive un provider nuovo
     * deve inciamparci.
     */
    public function hasActiveSession(string $rawToken): bool;
}
