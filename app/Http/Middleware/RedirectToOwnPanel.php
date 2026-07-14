<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEI GIÀ DENTRO, MA SUL PANNELLO SBAGLIATO: TI PORTO SUL TUO.
 *
 * ⚠️ I due pannelli **si respingono a vicenda**, e deve essere così: `/admin` gira in **bypass**
 * — vede gli armadi, gli utenti e il registro di **tutti i clienti** — e un gestore di locale non
 * ci deve mettere piede. Simmetricamente, `/app` vive dentro **un** locale, e un utente di
 * piattaforma non ne ha nessuno: non ha senso mostrargli una vista che è vuota per definizione.
 *
 * Questo confine sta in `User::canAccessPanel()`, e resta esattamente dov'è.
 *
 * ⚠️ **Ma il modo in cui veniva DETTO era sbagliato**: chi era già entrato e digitava
 * `/app` — o ci arrivava da un vecchio segnalibro, o da un link — sbatteva contro un **403**.
 * Un 403 a un utente autenticato che ha *un* pannello legittimo non è sicurezza: è scortesia.
 * Non protegge niente in più (il confine è già applicato) e fa sembrare rotto un sistema che
 * sta funzionando.
 *
 * Quindi: se il pannello corrente ti respinge **ma l'altro ti accetta**, ti ci porto.
 *
 * ⚠️ Se non ti accetta **nessuno dei due** (account sospeso, ruoli mancanti), NON si reindirizza:
 * si lascia passare, e sarà `Filament\Http\Middleware\Authenticate` a rispondere **403**. Un
 * reindirizzamento verso un pannello che a sua volta respinge sarebbe un **ciclo infinito** — il
 * modo classico di trasformare un errore chiaro in una pagina che non carica mai.
 *
 * ⚠️ Va registrato **PRIMA** di `Authenticate` in `authMiddleware()`: è quello che chiama
 * `canAccessPanel()` e aborta. Dopo, non verrebbe mai raggiunto.
 */
final class RedirectToOwnPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $utente = auth()->user();

        // Non autenticato: non c'è nessun "suo" pannello. Ci pensa Filament a mandarlo al login.
        if (! $utente instanceof User) {
            return $next($request);
        }

        $corrente = Filament::getCurrentPanel();

        if (! $corrente instanceof Panel || $utente->canAccessPanel($corrente)) {
            return $next($request);
        }

        $altro = $this->altroPannello($corrente);

        if ($altro instanceof Panel && $utente->canAccessPanel($altro)) {
            return redirect()->to($altro->getPath());
        }

        // Nessun pannello per lui: che sia Filament a dirlo, con un 403 onesto.
        return $next($request);
    }

    private function altroPannello(Panel $corrente): ?Panel
    {
        $id = $corrente->getId() === 'admin' ? 'app' : 'admin';

        try {
            return Filament::getPanel($id);
        } catch (\Throwable) {
            return null;
        }
    }
}
