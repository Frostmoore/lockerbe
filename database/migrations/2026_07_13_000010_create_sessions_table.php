<?php

use App\Domain\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Session = il rapporto temporaneo fra un cliente e un vano.
     *
     * Nasce quando qualcuno chiede un vano, muore al checkout (o a fine serata).
     * ⚠️ NON e' la sessione HTTP di Laravel: quella vive su Redis, e la tabella omonima
     * di Laravel e' stata rimossa in F0 apposta per liberare questo nome.
     *
     * Due scadenze, e sono cose diverse:
     *   `reserved_until` — quanto tempo hai per pagare, prima che il vano torni libero.
     *   `expires_at`     — la fine della serata: chiusura forzata (§7.4).
     */
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cabinet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('locker_id')->constrained()->cascadeOnDelete();

            $table->enum('status', ['created', 'active', 'closed', 'cancelled'])->default('created');

            $table->integer('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->uuid('payment_id')->nullable();

            // Il token che il cliente si porta via (link/QR) per riaprire e fare checkout
            // dal telefono, SENZA avere un account. Salvato come hash: se il database
            // finisse in mano a qualcuno, non conterrebbe le chiavi degli armadietti.
            $table->char('public_token_hash', 64)->nullable()->unique();

            $table->timestampTz('reserved_until');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->smallInteger('reopen_count')->default(0);
            $table->jsonb('meta')->default('{}');

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('locker_id');
            $table->index('reserved_until');
            $table->index('expires_at');
        });

        /*
         * ⚠️ IL VINCOLO PIU' IMPORTANTE DI QUESTA FASE.
         *
         * Al piu' UNA sessione non-terminale per vano. Senza, due clienti potrebbero
         * finire assegnati allo stesso armadietto: il secondo pagherebbe per un vano che
         * contiene il cappotto del primo, e lo aprirebbe.
         *
         * E' la seconda meta' della difesa contro la race di assegnazione: la prima e'
         * `lockForUpdate()` in LockerInventoryService. Servono ENTRAMBE — il lock protegge
         * dalla concorrenza sul percorso normale, questo indice protegge da tutto il resto
         * (un bug, una scrittura manuale, un percorso che qualcuno aggiungera' fra due anni).
         */
        DB::statement("
            CREATE UNIQUE INDEX one_active_session_per_locker
            ON sessions (locker_id)
            WHERE status IN ('created', 'active')
        ");

        Rls::enable('sessions');
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
