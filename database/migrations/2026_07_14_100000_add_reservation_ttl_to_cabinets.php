<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUANTO DURA UNA PRENOTAZIONE — configurabile, come il prezzo.
 *
 * Finora era una variabile d'ambiente (`LOCKER_RESERVATION_TTL`, 10 minuti): uguale per tutti
 * i clienti, cambiabile solo con un deploy. Ma e' una decisione **commerciale**, non tecnica:
 *
 *  - un locale di passaggio vuole una finestra corta (il vano si libera subito se il cliente
 *    ci ripensa);
 *  - un teatro, dove la gente paga con calma prima dello spettacolo, vuole una finestra lunga
 *    (una prenotazione che scade mentre il cliente cerca gli occhiali e' un cliente arrabbiato).
 *
 * ⚠️ Stessa cascata del prezzo — **armadio → locale → default** — e per lo stesso motivo: il
 * `null` significa *"segui il locale"*, non *"non impostato"*. Copiarci dentro il valore del
 * locale lo congelerebbe, e il giorno che il gestore lo cambia gli armadi non toccati
 * resterebbero al vecchio.
 *
 * ⚠️ In **secondi**. Nel pannello si scrive in minuti, perche' e' come ci si pensa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->unsignedInteger('reservation_ttl')
                ->nullable()
                ->after('tariff_cents')
                ->comment('secondi. NULL = eredita dal locale');
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropColumn('reservation_ttl');
        });
    }
};
