<?php

namespace App\Domain\Identity\Providers;

use App\Domain\Identity\Contracts\IdentityProvider;
use App\Models\Cabinet;
use App\Models\Identity;
use App\Models\Session;
use RuntimeException;

/**
 * Carta finta: un bottone "tap".
 *
 * ⚠️ La code-path e' **esattamente** quella che usera' la carta NFC vera (FH): il tap
 * produce un token grezzo, il token viene cercato fra le sessioni attive dell'armadio, e se
 * non e' legato a niente viene legato alla sessione attiva del vano. Quando arrivera'
 * l'hardware, cambiera' **solo chi produce il token** — non una riga di questa logica.
 *
 * ⚠️ Nota sulle carte vere: le carte bancarie e i wallet dei telefoni emettono un UID
 * **randomizzato a ogni lettura**, quindi sono inutilizzabili come identita'. Servono card
 * NFC dedicate, fornite dal locale, con UID stabile. E' un vincolo hardware, non una scelta.
 */
final class MockIdentityProvider implements IdentityProvider
{
    /**
     * Il token appartiene a una sessione **attiva** di questo armadio?
     *
     * Cerca per hash: il token in chiaro non esiste nel database, e non deve esistere.
     */
    public function resolve(string $rawToken, Cabinet $cabinet): ?Session
    {
        $identity = Identity::query()
            ->where('token_hash', Identity::hashToken($rawToken))
            ->whereHas('session', function ($query) use ($cabinet): void {
                $query->where('cabinet_id', $cabinet->id)->where('status', 'active');
            })
            ->latest('created_at')
            ->first();

        return $identity?->session()->first();
    }

    /**
     * Lega il token alla sessione: e' la "registrazione" della carta al primo uso.
     *
     * ⚠️ Una carta puo' essere legata al piu' a **una sessione attiva alla volta**. Se non
     * lo imponessimo, la stessa carta aprirebbe due armadietti diversi — e chiunque la
     * trovasse per terra avrebbe le chiavi di entrambi.
     *
     * Questo vincolo non e' esprimibile con un indice unico (dipende dallo stato di
     * un'altra tabella: vedi la migration `identities`), quindi vive qui — ed e' coperto da
     * un test.
     */
    public function bind(Session $session, string $rawToken): Identity
    {
        if (! $session->isActive()) {
            throw new RuntimeException('Non si lega una carta a una sessione non attiva.');
        }

        if ($this->hasActiveSession($rawToken)) {
            throw new RuntimeException('Questa carta e\' gia\' legata a un altro vano in uso.');
        }

        return Identity::create([
            'session_id' => $session->id,
            'type' => 'mock_card',
            'token_hash' => Identity::hashToken($rawToken),
        ]);
    }

    /**
     * ⚠️ Vedi il contratto: una carta tiene **al piu' un vano alla volta**.
     *
     * ⚠️ Non e' filtrata per armadio, ed e' deliberato: due vani in due armadi diversi dello
     * stesso locale sono comunque due vani, e la carta ne aprirebbe uno solo — l'altro
     * resterebbe chiuso con dentro la roba di qualcuno che ha pagato.
     *
     * (Il confine fra CLIENTI lo mette la RLS, non questa query: una carta di un altro locale
     * non e' nemmeno visibile.)
     */
    public function hasActiveSession(string $rawToken): bool
    {
        return Identity::query()
            ->where('token_hash', Identity::hashToken($rawToken))
            ->whereHas('session', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }
}
