<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DUE MODI DI PAGARE, DUE MODI DI IDENTIFICARSI.
 *
 * ⚠️ **L'identita' nasce DAL PAGAMENTO.** E' il cambio di rotta di questa migration, e il
 * motivo per cui esiste: prima l'identita' si attaccava dopo, con un tap a vuoto, e chi
 * pagava col QR non ne riceveva nessuna — poi premeva "ho finito" e non succedeva niente.
 *
 *   QR   → il cliente paga sul telefono e lascia la sua **email**; il server gli manda un
 *          **codice a 6 cifre**, che digitera' sul chiosco. L'email non la chiede il chiosco:
 *          digitare un indirizzo su un touchscreen, in un locale affollato, e' un modo
 *          affidabile di sbagliarlo.
 *
 *   NFC  → il cliente paga con la carta, e il **provider di pagamento** restituisce un token
 *          stabile di quella carta. Quel token E' l'identita': la stessa carta, riappoggiata,
 *          riapre il vano.
 *
 * ⚠️ Sul NFC pende un vincolo accertato: **il FCV5003 non puo' incassare EMV** (no secure
 * element, no PCI, DejaOS non esegue SoftPOS). Il cliente sostiene che il provider dica il
 * contrario; il codice e' scritto contro il contratto `PaymentProvider`, quindi se hanno
 * ragione si innesta il driver e nient'altro si muove — e se hanno torto, si cambia driver.
 * **Resta da farsi dire da loro se il token della carta torna stabile anche a importo zero**:
 * senza, la riapertura non funziona (D1/D2/D3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            // Come ha pagato: decide *tutto* il resto del flusso di identita'.
            $table->enum('payment_method', ['qr', 'nfc'])->default('qr')->after('currency');

            // ⚠️ Solo per il flusso QR, e solo per mandare il codice. Non serve a
            // identificare nessuno: l'identita' e' il codice, non l'indirizzo.
            $table->string('customer_email')->nullable()->after('payment_method');
        });

        /*
         * ⚠️ `enum()` su Postgres, in Laravel, non e' un tipo enum: e' un `varchar` con un
         * CHECK constraint. Aggiungere un valore vuol dire buttare via il vincolo e rifarlo —
         * non basta cambiare la migration originale, che e' gia' girata.
         */
        DB::statement('ALTER TABLE identities DROP CONSTRAINT IF EXISTS identities_type_check');

        DB::statement(
            "ALTER TABLE identities ADD CONSTRAINT identities_type_check
             CHECK (type::text = ANY (ARRAY['nfc_card'::character varying, 'web_token'::character varying,
                                            'mock_card'::character varying, 'access_code'::character varying]::text[]))"
        );
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn(['payment_method', 'customer_email']);
        });

        DB::statement('ALTER TABLE identities DROP CONSTRAINT IF EXISTS identities_type_check');

        DB::statement(
            "ALTER TABLE identities ADD CONSTRAINT identities_type_check
             CHECK (type::text = ANY (ARRAY['nfc_card'::character varying, 'web_token'::character varying,
                                            'mock_card'::character varying]::text[]))"
        );
    }
};
