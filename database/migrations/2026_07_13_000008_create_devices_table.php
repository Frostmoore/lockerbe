<?php

use App\Domain\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device = il FCV5003 fisico. Relazione 1:1 col Cabinet (`cabinet_id` unique).
     *
     * `mqtt_client_id` e `credential_fingerprint` esistono gia' ora, anche se MQTT arriva
     * in F5: sono l'identita' con cui il device si presentera' al broker, e l'ACL per-device
     * (piano §3.3) e' il confine tra clienti sul canale realtime. Averli qui significa che
     * F5 non dovra' toccare questa tabella.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cabinet_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('serial', 64)->unique();
            $table->string('model', 40)->default('VF203_V12');
            $table->string('mqtt_client_id', 80)->unique();
            $table->string('credential_fingerprint', 128)->nullable();

            $table->string('firmware_version', 40)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('mac_address', 24)->nullable();
            $table->timestampTz('last_seen_at')->nullable();

            $table->enum('status', ['provisioned', 'online', 'offline', 'revoked'])
                ->default('provisioned');

            $table->timestamps();

            $table->index('tenant_id');
        });

        Rls::enable('devices');
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
