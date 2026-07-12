<?php

use App\Domain\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Row Level Security (piano §3.2) — la rete di sicurezza vera del multi-tenant.
     *
     * Il livello applicativo (global scope, §3.1) copre il 99% dei casi. Questa
     * migration copre l'1% che resta: la query grezza, il `DB::select` scritto di
     * fretta, la relazione caricata senza scope. In un sistema che mostra dati, quel
     * residuo e' una fuga; in un sistema che apre serrature, e' l'armadietto di un
     * cliente aperto da un altro.
     *
     * Funziona SOLO perche' l'applicazione gira come `locker_app`, che non e' superuser
     * e non possiede le tabelle (vedi docker/postgres/init). Un giorno che qualcuno
     * mettesse credenziali da owner nel .env, tutto questo diventerebbe decorativo:
     * per questo il test di F0 verifica i flag del ruolo a ogni run.
     *
     * F2-F4 aggiungeranno cabinets, devices, lockers, sessions, payments, identities,
     * commands: OGNUNA di quelle migration deve chiamare Rls::enable().
     */
    public function up(): void
    {
        // Il tenant vede se stesso e nessun altro: qui la colonna e' `id`.
        Rls::enable('tenants', 'id');

        Rls::enable('sites');
        Rls::enable('users');
        Rls::enable('audit_logs');
    }

    public function down(): void
    {
        foreach (['tenants', 'sites', 'users', 'audit_logs'] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
