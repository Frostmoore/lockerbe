<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il segreto del device, in forma **rileggibile** — e perche' ci serve.
     *
     * Finora il server teneva solo l'**impronta** (`credential_fingerprint`): perfetto per
     * *verificare* chi bussa, inutile per *firmare*. Ma da F4 il server deve firmare ogni
     * comando in HMAC con la chiave di quel device, e un hash non si puo' usare come chiave.
     *
     * Quindi il segreto va conservato in modo recuperabile. E' **cifrato a riposo** con
     * `APP_KEY` (cast `encrypted` sul model): chi ruba il database senza la chiave applicativa
     * non ottiene comandi validi.
     *
     * ⚠️ Perche' la firma serve, visto che il canale sara' gia' cifrato (TLS)? Perche' il TLS
     * protegge il **filo**, non il **messaggio**: chi dovesse ottenere l'accesso al broker
     * — un bug di ACL, una credenziale di servizio finita male — potrebbe pubblicare un
     * `open` sul topic di un armadio qualsiasi. La firma fa si' che quel messaggio venga
     * **scartato dal device**, perche' non e' stato prodotto da chi ha la chiave.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->text('signing_secret')->nullable()->after('credential_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('signing_secret');
        });
    }
};
