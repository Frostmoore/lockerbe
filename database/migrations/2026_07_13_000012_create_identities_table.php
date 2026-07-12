<?php

use App\Domain\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identita': cio' che il cliente presenta per riaprire il PROPRIO vano.
     *
     * Oggi una carta mock (un bottone). Domani una card NFC (F6/FH) o un token web —
     * dipende da D2. La code-path e' gia' la stessa: cambia solo chi produce il token.
     *
     * ⚠️ `token_hash`, mai il token in chiaro. Un token di identita' in chiaro nel database
     * e' la chiave di un armadietto scritta su un post-it: chi legge il DB apre i vani.
     * Per lo stesso motivo non finisce nemmeno nei log o in `audit_logs.context`.
     *
     * ⚠️ Deviazione dal piano §5, deliberata: il piano prevedeva
     * `unique(tenant_id, token_hash)`. Non si puo': la stessa card, usata di nuovo la
     * settimana dopo, e' legittima e produrrebbe un secondo record. Un unique globale la
     * renderebbe inutilizzabile per sempre dopo il primo uso.
     * L'unicita' che serve davvero — "una carta e' legata al piu' a una sessione ATTIVA" —
     * dipende dallo stato di un'ALTRA tabella, e Postgres non sa esprimerla in un indice.
     * La impone `MockIdentityProvider::bind()`, ed e' verificata da un test.
     */
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['nfc_card', 'web_token', 'mock_card']);
            $table->char('token_hash', 64);          // SHA-256

            $table->timestampTz('last_used_at')->nullable();
            $table->timestamps();

            // Su questo indice gira la risoluzione della carta al momento del tap.
            $table->index(['tenant_id', 'token_hash']);
        });

        Rls::enable('identities');
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
