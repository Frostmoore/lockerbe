<?php

use App\Domain\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Locker = un vano. E' l'oggetto fisico che si apre.
     *
     * `board_address` + `channel` sono l'indirizzo RS-485 sulla scheda serrature: la coppia
     * che, un domani, dira' al device QUALE serratura scattare.
     *
     * ⚠️ I due unique non sono formalita':
     *   - (cabinet_id, number)                 due vani con lo stesso numero visibile
     *   - (cabinet_id, board_address, channel) DUE VANI SULLO STESSO CANALE RS-485
     * Il secondo e' quello pericoloso: significherebbe che aprendo il vano 7 si apre anche
     * il 12, cioe' l'armadietto di qualcun altro. Il database non deve nemmeno permettere
     * di scriverlo.
     *
     * ⚠️ Nota onesta: questo vincolo garantisce che la MAPPA nel database sia coerente. NON
     * garantisce che corrisponda alla realta' fisica — se il cablaggio e' invertito, il
     * database non se ne accorge. La verifica vano per vano e' in FH (bring-up hardware).
     */
    public function up(): void
    {
        Schema::create('lockers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cabinet_id')->constrained()->cascadeOnDelete();

            $table->smallInteger('number');            // numero visibile all'utente
            $table->smallInteger('board_address');     // mappa RS-485
            $table->smallInteger('channel');

            $table->enum('status', ['free', 'reserved', 'occupied', 'checkout', 'out_of_service'])
                ->default('free');

            // FK verso `sessions` NON dichiarata: quella tabella nasce in F3. La colonna
            // esiste gia' per non dover riscrivere la tabella dopo.
            $table->uuid('current_session_id')->nullable();

            $table->timestampTz('last_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['cabinet_id', 'number']);
            $table->unique(['cabinet_id', 'board_address', 'channel']);

            // L'indice su cui poggia l'assegnazione "primo vano libero" (piano §11.1).
            $table->index(['tenant_id', 'cabinet_id', 'status']);
        });

        Rls::enable('lockers');
    }

    public function down(): void
    {
        Schema::dropIfExists('lockers');
    }
};
