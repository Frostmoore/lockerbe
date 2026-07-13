<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IL PREZZO, PER ARMADIO.
 *
 * Finora la tariffa era **una sola per locale** (`tenants.settings['tariff_cents']`). Ma un
 * locale puo' avere l'armadio all'ingresso e quello in fondo al corridoio, o vani grandi e
 * vani piccoli: il prezzo e' una proprieta' del **posto in cui metti la roba**, non
 * dell'azienda.
 *
 * ⚠️ **Nullable, e il null significa qualcosa**: "eredita dal locale". Non e' un default
 * mancante — e' la scelta di *non* decidere per questo armadio, e di seguire il listino del
 * locale se un giorno cambia. Mettere qui una copia del prezzo del locale sarebbe peggio: il
 * giorno che il gestore ritocca il listino, gli armadi che non ha toccato resterebbero al
 * prezzo vecchio senza che nessuno se ne accorga.
 *
 * ⚠️ In **centesimi**, sempre. I float non tengono i soldi: `0.1 + 0.2 !== 0.3`, e un
 * guardaroba che sbaglia un centesimo mille volte a sera lo sbaglia in bilancio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->unsignedInteger('tariff_cents')
                ->nullable()
                ->after('status')
                ->comment('NULL = eredita la tariffa del locale');
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropColumn('tariff_cents');
        });
    }
};
