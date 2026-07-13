<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il provisioning di un chiosco, come avviene davvero in campo:
     *
     *   1. l'armadio arriva in sede (lamiera, sportelli, serrature, e un FCV5003 avvitato
     *      in mezzo — e' UN oggetto solo);
     *   2. il tecnico registra il dispositivo sul server leggendone il **serial** dall'etichetta;
     *   3. il tecnico crea l'armadio (vani, mappa serrature) e lo lega a quel dispositivo;
     *   4. il tecnico preme **Attiva**: il chiosco che si accende ritira le sue credenziali.
     *
     * ⚠️ Il dispositivo nasce **dentro un tenant**, creato da un tecnico autenticato: non serve
     * piu' nessuna "anticamera" per i chioschi senza padrone, ne' un codice da leggere sullo
     * schermo per capire chi sta bussando. **Il server sa gia' chi e'**, perche' gliel'ha
     * detto un umano prima ancora che il dispositivo venisse acceso.
     *
     * (Nel database Cabinet e Device restano due tabelle. Non perche' siano due oggetti
     * fisici — sono uno solo — ma perche' l'elettronica si rompe e la lamiera no: quando il
     * FCV5003 fuma e lo si sostituisce, l'armadio, i suoi vani, le sue sessioni e il suo audit
     * devono sopravvivere.)
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Il dispositivo si registra PRIMA dell'armadio: per un attimo non ne ha uno.
            $table->uuid('cabinet_id')->nullable()->change();

            /*
             * ⚠️ LA FINESTRA DI ATTIVAZIONE.
             *
             * Il tecnico preme "Attiva" quando il chiosco e' li', montato e acceso. Da quel
             * momento, per pochi minuti, il server consegna le credenziali al dispositivo che
             * si presenta con quel serial.
             *
             * E' un gesto umano, deliberato e a tempo: fuori dalla finestra, chiunque bussi con
             * quel serial — foss'anche il chiosco vero — non ottiene niente.
             */
            $table->timestampTz('activation_expires_at')->nullable()->after('status');

            // Le credenziali in attesa di essere ritirate, cifrate. Consegnate UNA volta sola:
            // dopo, sul server resta solo l'impronta (`credential_fingerprint`).
            $table->text('credentials_payload')->nullable()->after('activation_expires_at');
            $table->timestampTz('credentials_delivered_at')->nullable()->after('credentials_payload');

            $table->timestampTz('activated_at')->nullable()->after('credentials_delivered_at');
            $table->uuid('activated_by')->nullable()->after('activated_at');

            $table->index('serial');
        });

        // `registered` = il tecnico l'ha inserito nel server, ma il chiosco non si e' ancora
        // fatto vivo. E' lo stato iniziale.
        DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS devices_status_check');
        DB::statement("
            ALTER TABLE devices ADD CONSTRAINT devices_status_check
            CHECK (status IN ('registered', 'provisioned', 'online', 'offline', 'revoked'))
        ");
        DB::statement("ALTER TABLE devices ALTER COLUMN status SET DEFAULT 'registered'");
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'activation_expires_at', 'credentials_payload',
                'credentials_delivered_at', 'activated_at', 'activated_by',
            ]);
        });
    }
};
