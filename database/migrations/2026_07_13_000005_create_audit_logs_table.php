<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log (piano §14) — append-only, con hash-chain.
     *
     * Non e' burocrazia: e' la prova che serve **il giorno che sparisce un cappotto**
     * e il cliente chiede chi ha aperto cosa, quando e da dove. Un registro che si puo'
     * modificare a posteriori non e' una prova; per questo:
     *
     *  - `REVOKE UPDATE, DELETE` a livello di DATABASE, non di applicazione. Nemmeno un
     *    bug (o un dipendente) puo' riscrivere la storia passando da Eloquent.
     *  - hash-chain: ogni record incatena l'hash del precedente. Cancellare o alterare
     *    una riga rompe la catena, e `audit:verify-chain` se ne accorge.
     *
     * Le FK verso cabinet/locker/session/command NON sono vincoli: quelle tabelle
     * nascono in F2-F4, e comunque un audit deve sopravvivere alla cancellazione di
     * cio' che descrive.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->nullable();

            $table->enum('actor_type', ['user', 'device', 'system', 'webhook']);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_role', 40)->nullable();

            $table->enum('source', ['web', 'api', 'mobile', 'nfc', 'device', 'system', 'webhook']);
            $table->string('action', 60);   // locker.open, session.checkout, ota.push, ...

            $table->uuid('cabinet_id')->nullable();
            $table->uuid('locker_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->uuid('command_id')->nullable();

            $table->enum('result', ['ok', 'fail']);
            $table->string('error_code', 60)->nullable();

            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable();

            $table->jsonb('context')->default('{}');

            // Precisione al MICROSECONDO, esplicita. Laravel di default crea
            // `timestamp(0)`, cioe' tronca ai secondi: l'hash calcolato in scrittura
            // conterrebbe i microsecondi, quello ricalcolato rileggendo dal database no,
            // e la catena risulterebbe rotta su ogni singolo record.
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->index(['tenant_id', 'created_at']);
            $table->index('cabinet_id');
            $table->index('locker_id');
            $table->index('action');
        });

        // Append-only imposto dal database. Senza questo, "append-only" e' solo una
        // buona intenzione dell'applicazione — e le buone intenzioni non reggono in
        // tribunale ne' contro un bug.
        DB::statement('REVOKE UPDATE, DELETE ON audit_logs FROM locker_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
