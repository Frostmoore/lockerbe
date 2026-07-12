<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site = sede di un tenant. OPZIONALE (piano §2): oggi nessuno la usa.
     *
     * Esiste comunque da subito perche' aggiungere una FK a tabelle gia' piene, in
     * produzione, costa molto piu' di una tabella inutilizzata.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('address')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
