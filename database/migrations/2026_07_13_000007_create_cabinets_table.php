<?php

use App\Domain\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabinet = un Armadio. Un armadio = un dispositivo FCV5003 = N vani.
     *
     * ⚠️ Cabinet e Device sono entita' SEPARATE (piano §2), e non e' pedanteria: se un
     * FCV5003 si rompe e va sostituito, l'Armadio — con i suoi vani, la sua storia, le
     * sue sessioni — sopravvive, e gli si associa un Device nuovo. Fonderli significherebbe
     * perdere (o duplicare) la storia a ogni sostituzione.
     */
    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 120);
            $table->string('code', 40);

            // `maintenance` e' l'unico stato deciso da un umano. `online`/`offline` sono
            // DERIVATI dall'heartbeat (vedi MarkOfflineCabinets): nessuno li scrive a mano.
            $table->enum('status', ['online', 'offline', 'maintenance'])->default('offline');

            $table->string('firmware_version', 40)->nullable();
            $table->timestampTz('last_seen_at')->nullable();

            // Mappa dei vani e policy locali. Chiavi usate: channels_per_board.
            $table->jsonb('settings')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
            $table->index('last_seen_at');
        });

        Rls::enable('cabinets');
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinets');
    }
};
