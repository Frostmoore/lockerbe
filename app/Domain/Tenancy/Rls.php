<?php

namespace App\Domain\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Attiva la Row Level Security su una tabella tenant-scoped.
 *
 * Va chiamata da OGNI migration che crea una tabella con `tenant_id`. Se ci si
 * dimentica, quella tabella resta senza rete: l'unica difesa diventa ricordarsi di
 * scrivere `WHERE tenant_id` in ogni query, e prima o poi qualcuno se ne dimentica.
 * Per lockers e commands, "dimenticarsene" significa aprire l'armadietto di un altro
 * cliente.
 *
 * La policy e' **fail-closed**: se il contesto non e' impostato (nessun tenant e
 * nessun bypass), non si vede NIENTE. E' deliberato. L'alternativa — mostrare tutto
 * quando il contesto manca — trasformerebbe un contesto dimenticato in una fuga di
 * dati silenziosa, che e' esattamente il modo in cui questi bug arrivano in produzione.
 *
 * Le due variabili di sessione sono impostate da TenantContext:
 *   app.tenant_id   uuid del tenant corrente
 *   app.bypass_rls  'on' per platform_admin e per il codice di sistema
 */
final class Rls
{
    public static function enable(string $table, string $column = 'tenant_id'): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        // FORCE: le policy valgono ANCHE per il proprietario della tabella. Senza,
        // qualunque connessione come locker_owner le scavalcherebbe.
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        DB::statement("
            CREATE POLICY tenant_isolation ON {$table}
            USING (
                NULLIF(current_setting('app.bypass_rls', true), '') = 'on'
                OR {$column} = NULLIF(current_setting('app.tenant_id', true), '')::uuid
            )
            WITH CHECK (
                NULLIF(current_setting('app.bypass_rls', true), '') = 'on'
                OR {$column} = NULLIF(current_setting('app.tenant_id', true), '')::uuid
            )
        ");
    }
}
