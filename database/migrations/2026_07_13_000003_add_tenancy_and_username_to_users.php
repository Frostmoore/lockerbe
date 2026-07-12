<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aggancio degli utenti al tenant, e login per username.
     *
     * `tenant_id` NULL significa una cosa sola e precisa: **platform_admin**, cioe' noi.
     * Chiunque altro appartiene a un tenant e non deve poter vedere — ne' aprire — nulla
     * al di fuori del proprio (piano §4). La regola e' imposta anche dal database:
     * vedi la migration della Row Level Security.
     *
     * `username` non era nel piano: e' stato aggiunto perche' l'accesso al pannello
     * avviene con username, non con l'email. Il login accetta comunque entrambi.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();

            $table->string('username', 60)->nullable()->unique()->after('tenant_id');

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('username');
        });
    }
};
