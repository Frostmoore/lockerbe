<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracce dell'accoppiamento sul dispositivo, una volta che ha un tenant.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestampTz('paired_at')->nullable()->after('status');

            // Chi ha premuto il bottone. L'accoppiamento e' un atto umano, e deve avere un
            // nome: e' quello che si guarda il giorno che un chiosco risulta legato
            // all'armadio sbagliato.
            $table->uuid('paired_by')->nullable()->after('paired_at');

            /*
             * ⚠️ Un dispositivo GIA' accoppiato che si ripresenta SENZA credenziali.
             *
             * Succede legittimamente (memoria azzerata da un reflash, un factory reset, un OTA
             * finito male) ma e' anche esattamente cio' che farebbe un impostore che conosce
             * il serial. Il server non puo' distinguere i due casi — quindi non prova nemmeno:
             * **non ri-fida nessuno da solo**. Segna la richiesta, e chiede a un umano.
             *
             * Nel frattempo le credenziali vecchie restano valide: invalidarle qui darebbe a
             * chiunque conosca un serial il potere di buttare fuori un chiosco vero.
             */
            $table->timestampTz('reenrollment_requested_at')->nullable()->after('paired_by');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['paired_at', 'paired_by', 'reenrollment_requested_at']);
        });
    }
};
