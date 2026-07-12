<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Impostazioni di piattaforma, modificabili dall'admin a sistema acceso.
     *
     * Serve perche' alcune scelte non possono vivere in `.env`: se l'unico modo di
     * disattivare la MFA fosse una variabile d'ambiente, per cambiarla servirebbe un
     * deploy — e nessun gestore di un locale puo' fare un deploy.
     *
     * Non e' tenant-scoped: e' la piattaforma. Il singolo tenant puo' comunque
     * sovrascrivere alcune chiavi in `tenants.settings` (es. `require_mfa`).
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
