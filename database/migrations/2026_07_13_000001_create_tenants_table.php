<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant = un cliente. Tipicamente un locale, con i suoi armadi e il suo pannello.
     *
     * `timezone` non e' un vezzo: la "fine serata" attraversa la mezzanotte, e il piano
     * (§7.4) vieta ogni logica basata sul giorno solare. Le sessioni si chiudono a un
     * `expires_at` esplicito, calcolato nel fuso del tenant.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 80)->unique();
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->string('timezone', 64)->default('Europe/Rome');

            // Tariffe, policy di sessione e override locali (es. require_mfa).
            $table->jsonb('settings')->default('{}');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
