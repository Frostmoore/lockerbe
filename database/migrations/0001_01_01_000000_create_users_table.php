<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema utenti (piano §5).
     *
     * Due scelte che divergono dallo scheletro Laravel, entrambe deliberate:
     *
     * 1. PK UUID v7 (non bigint auto-increment): ordinabile nel tempo come un
     *    seriale, ma non enumerabile dall'esterno. Vale per tutte le tabelle.
     *
     * 2. NIENTE tabella `sessions` di Laravel. Quel nome appartiene al dominio:
     *    una `session` qui e' il rapporto fra un utente e un vano del guardaroba
     *    (piano §5, creata in F3). Le sessioni HTTP vivono su Redis.
     *
     * `tenant_id` arriva in F1 insieme alla tabella `tenants` che referenzia.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // MFA: obbligatoria per chi puo' aprire vani (platform_admin, tenant_admin — §4).
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
    }
};
