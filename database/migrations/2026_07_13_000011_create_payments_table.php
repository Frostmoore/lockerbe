<?php

use App\Domain\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagamenti.
     *
     * `provider` = `mock` finche' D1 non e' sbloccata col cliente. Il passaggio a Nexi
     * (F8) non tocca questa tabella: cambia solo chi implementa il contratto PaymentProvider.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained()->cascadeOnDelete();

            $table->enum('provider', ['mock', 'nexi']);
            $table->string('provider_ref', 120)->nullable();

            $table->integer('amount_cents');
            $table->char('currency', 3)->default('EUR');

            $table->enum('status', [
                'created', 'pending', 'authorized', 'confirmed', 'failed', 'refunded',
            ])->default('created');

            $table->jsonb('payload')->default('{}');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);

            /*
             * ⚠️ Idempotenza del webhook, imposta dal DATABASE.
             *
             * Un provider di pagamento rimanda lo stesso webhook piu' volte: e' normale, ed
             * e' previsto dai loro contratti. Se ogni consegna producesse un pagamento
             * confermato, produrrebbe anche un comando di apertura — e il vano si
             * riaprirebbe da solo, a ogni retry del provider.
             *
             * Questo unique fa si' che il secondo webhook non possa creare nulla di nuovo.
             */
            $table->unique(['provider', 'provider_ref']);
        });

        Rls::enable('payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
