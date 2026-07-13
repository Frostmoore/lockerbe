<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La riconsegna in corso.
     *
     * Il nodo che questa colonna risolve: **il sistema non puo' sapere se un vano e' vuoto.**
     * Sa (forse, dipende da D5) se lo sportello e' chiuso, ma non sa se dentro c'e' ancora
     * un cappotto.
     *
     * Da qui l'asimmetria che governa tutto: liberare un vano per sbaglio significa
     * assegnarlo a un altro cliente **con dentro la roba di qualcuno** — il danno peggiore
     * che questo sistema possa fare. Tenerlo occupato per sbaglio costa qualche euro di
     * rotazione, e lo staff lo recupera in trenta secondi. Percio': **nel dubbio, il vano
     * resta occupato.**
     *
     * Il cliente dichiara l'intenzione **prima** che il vano si apra ("riapro" / "ho
     * finito"), perche' dopo aver ripreso il cappotto se ne va — sono le tre di notte, e non
     * tornera' al chiosco a premere un bottone di conferma. Se dichiara "ho finito", la
     * sessione entra in riconsegna: il vano si apre e resta SUO (stato `checkout`) finche'
     * la riconsegna non e' confermata.
     */
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            // Valorizzata = riconsegna in corso. La sessione e' ancora `active`: se il
            // cliente si accorge di aver dimenticato qualcosa e ripassa la carta, il vano
            // si riapre e la riconsegna si annulla.
            $table->timestampTz('checkout_pending_at')->nullable()->after('paid_at');

            // Chi ha confermato che il vano e' stato svuotato: lo sportello richiuso
            // (device), la finestra di cortesia scaduta (timer), o un operatore.
            $table->enum('closed_by', ['device', 'timeout', 'staff', 'expiry'])->nullable()->after('closed_at');

            $table->index('checkout_pending_at');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn(['checkout_pending_at', 'closed_by']);
        });
    }
};
