<?php

use App\Domain\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * I COMANDI — il cuore della sicurezza fisica (piano §8).
     *
     * ⚠️ **Il rischio #1 del sistema.** Un `open` accodato mentre l'armadio e' offline e
     * consegnato tre ore dopo **apre un vano pieno di roba alle 4 del mattino**. Con MQTT
     * questo accade **di default** (retained, QoS con sessione persistente). Ogni colonna qui
     * sotto esiste per impedirlo.
     */
    public function up(): void
    {
        Schema::create('commands', function (Blueprint $table) {
            /*
             * ⚠️ La PK **e'** l'Idempotency-Key.
             *
             * Il client (pannello, chiosco, webhook) genera un uuid e lo manda nell'header. Se
             * la rete gli fa credere che la richiesta sia fallita e riprova, la chiave e' la
             * stessa: il comando esistente viene restituito, e il vano **non si riapre una
             * seconda volta**. Senza questo, un retry di rete = un'apertura in piu'.
             */
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cabinet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('locker_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('session_id')->nullable();

            $table->enum('type', ['open', 'sync', 'ota', 'reboot']);
            $table->enum('reason', ['store', 'reopen', 'checkout', 'admin', 'maintenance']);

            $table->jsonb('payload')->default('{}');

            $table->enum('status', ['pending', 'sent', 'acked', 'failed', 'expired'])
                ->default('pending');

            $table->enum('issued_by_type', ['user', 'system', 'webhook', 'device']);
            $table->uuid('issued_by_id')->nullable();

            $table->timestampTz('issued_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('acked_at')->nullable();

            /*
             * ⚠️ **NOT NULL. Non e' negoziabile.**
             *
             * Ogni comando scade. Il payload che parte verso il device **contiene** questa
             * data, e il device rifiuta i comandi scaduti: il controllo esiste due volte, sul
             * server e sul dispositivo, perche' un comando che sopravvive al proprio senso e'
             * un armadietto che si apre da solo nel cuore della notte.
             */
            $table->timestampTz('expires_at');

            $table->smallInteger('attempts')->default(0);
            $table->jsonb('result')->nullable();

            // HMAC-SHA256 su id|type|locker|expires_at, con la chiave del singolo device.
            // Il device verifica: un comando non firmato, o firmato male, non viene eseguito.
            $table->char('signature', 64)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'cabinet_id', 'status']);
            $table->index(['status', 'expires_at']);   // usato dal job di scadenza
            $table->index('session_id');
        });

        Rls::enable('commands');
    }

    public function down(): void
    {
        Schema::dropIfExists('commands');
    }
};
