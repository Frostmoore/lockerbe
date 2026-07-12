<?php

namespace App\Support;

/**
 * Il cancello dei bottoni mock (piano §12).
 *
 * ⚠️ E' **doppio**, e i due lucchetti sono indipendenti:
 *   1. l'ambiente non deve essere `production`;
 *   2. `locker.mock_panel` deve essere acceso.
 *
 * Il motivo di tanta cautela: dietro questo cancello c'e' un endpoint che **conferma un
 * pagamento senza che nessuno abbia pagato**. In produzione sarebbe un modo gratuito di
 * farsi aprire un armadietto. Non basta proteggerlo con un permesso — non deve **esistere**:
 * le rotte non vengono proprio registrate, e la risposta e' 404, non 403.
 *
 * Vive in una classe propria, e non inline nel file delle rotte, per una ragione pratica:
 * cosi' e' verificabile da un test senza dover rifare il boot dell'applicazione.
 */
final class MockPanel
{
    public static function enabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return (bool) config('locker.mock_panel');
    }
}
