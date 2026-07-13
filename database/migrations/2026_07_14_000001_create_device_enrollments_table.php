<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'anticamera: i chioschi che si sono presentati ma **non appartengono ancora a
     * nessuno**.
     *
     * ⚠️ Perche' una tabella separata e non una riga in `devices`: `devices` e' tenant-scoped
     * con RLS attiva, e un chiosco appena tirato fuori dalla scatola **non ha un tenant** —
     * non si sa ancora di quale locale sara'. Metterlo li' con `tenant_id` nullo
     * significherebbe un record che nessuna policy puo' proteggere (e infatti
     * `BelongsToTenant` si rifiuta di crearlo). Qui invece la mancanza di tenant e' lo stato
     * normale: e' un'anticamera, non il salotto.
     *
     * ⚠️ **L'identita' e' il `serial`, non un uuid che il device si inventa.** Un uuid casuale
     * salvato in memoria sopravvive a un calo di corrente, ma NON a un reflash del firmware,
     * a un factory reset, a un OTA andato storto o a una memoria corrotta: in tutti quei casi
     * il device se ne genererebbe uno nuovo e per il server diventerebbe **un estraneo mai
     * visto**, lasciando l'armadio orfano. Il serial invece e' dell'hardware: sopravvive a
     * qualunque azzeramento, e non si puo' rigenerare.
     */
    public function up(): void
    {
        Schema::create('device_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // L'identita' vera, quella che l'hardware si porta addosso.
            $table->string('serial', 64)->unique();
            $table->string('model', 40)->nullable();
            $table->string('mac_address', 24)->nullable();
            $table->ipAddress('ip_address')->nullable();

            /*
             * Il codice che il chiosco mostra SUL PROPRIO SCHERMO.
             *
             * ⚠️ E' questo che impedisce di accoppiare il chiosco all'armadio SBAGLIATO —
             * l'errore piu' pericoloso possibile in fase di installazione, perche' un chiosco
             * legato all'armadio sbagliato apre l'armadietto di uno sconosciuto a ogni
             * singola richiesta, e il software da solo non puo' accorgersene MAI.
             *
             * Nessun automatismo puo' sapere quale armadio ha davanti un dispositivo: lo sa
             * solo un essere umano che ci sta davanti. Il codice a schermo e' la prova che il
             * tecnico sta guardando **proprio quel** chiosco, non uno qualsiasi di un elenco.
             */
            $table->char('pairing_code', 6)->nullable();
            $table->timestampTz('pairing_code_expires_at')->nullable();

            $table->enum('status', ['pending', 'paired', 'rejected'])->default('pending');

            // Le credenziali emesse all'accoppiamento, cifrate, in attesa che il device le
            // venga a prendere. Consegnate UNA volta sola, poi cancellate: sul server ne resta
            // solo l'impronta (`devices.credential_fingerprint`).
            $table->text('credentials_payload')->nullable();
            $table->timestampTz('credentials_delivered_at')->nullable();

            $table->uuid('device_id')->nullable();   // valorizzato all'accoppiamento
            $table->timestamps();

            $table->index('pairing_code');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_enrollments');
    }
};
