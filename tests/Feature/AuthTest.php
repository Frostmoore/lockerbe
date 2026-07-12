<?php

use App\Domain\Auth\MfaService;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
 * Login, ruoli, e verifica in due passaggi (piano §4, §17.8).
 */

it('fa entrare con lo username', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->forTenant($tenant)->create([
        'username' => 'gestore',
        'password' => Hash::make('segretissima'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'login' => 'gestore',
        'password' => 'segretissima',
    ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'roles', 'mfa']]);
});

it('fa entrare anche con l\'email', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->forTenant($tenant)->create([
        'username' => 'gestore2',
        'email' => 'gestore@locale.test',
        'password' => Hash::make('segretissima'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'login' => 'gestore@locale.test',
        'password' => 'segretissima',
    ])->assertOk();
});

it('respinge le credenziali sbagliate senza rivelare se l\'utente esiste', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->forTenant($tenant)->create(['username' => 'gestore']);

    $esiste = $this->postJson('/api/v1/auth/login', [
        'login' => 'gestore', 'password' => 'sbagliata',
    ])->assertStatus(422);

    $nonEsiste = $this->postJson('/api/v1/auth/login', [
        'login' => 'fantasma', 'password' => 'sbagliata',
    ])->assertStatus(422);

    // Stessa risposta nei due casi: distinguerli regalerebbe a chiunque un modo per
    // scoprire quali account esistono.
    expect($esiste->json('error.details'))->toBe($nonEsiste->json('error.details'));
});

it('nega a tenant_staff i permessi riservati (open_all, ota)', function () {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->forTenant($tenant)->create();
    $staff->assignRole('tenant_staff');

    // §17.8: lo staff apre un vano, ma non puo' svuotare l'armadio ne' toccare i firmware.
    expect($staff->can('locker.open'))->toBeTrue()
        ->and($staff->can('locker.open_all'))->toBeFalse()
        ->and($staff->can('ota.manage'))->toBeFalse()
        ->and($staff->can('cabinet.manage'))->toBeFalse();
});

it('da\' a tenant_admin il proprio locale ma non l\'OTA', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignRole('tenant_admin');

    expect($admin->can('locker.open_all'))->toBeTrue()
        ->and($admin->can('user.manage'))->toBeTrue()
        // Un firmware difettoso spedito a tutti mette fuori uso gli armadi di TUTTI i
        // clienti: non e' una decisione che spetta al singolo locale.
        ->and($admin->can('ota.manage'))->toBeFalse()
        ->and($admin->can('tenant.manage'))->toBeFalse();
});

it('chiede il secondo fattore quando la MFA e\' attiva', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'username' => 'gestore',
        'password' => Hash::make('segretissima'),
    ]);

    /** @var MfaService $mfa */
    $mfa = app(MfaService::class);
    $secret = $mfa->generateSecret();

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    // Password giusta, ma senza codice: non si entra.
    $this->postJson('/api/v1/auth/login', [
        'login' => 'gestore', 'password' => 'segretissima',
    ])->assertStatus(401)->assertJsonPath('error.code', 'mfa_code_required');

    // Password giusta e codice sbagliato: non si entra.
    $this->postJson('/api/v1/auth/login', [
        'login' => 'gestore', 'password' => 'segretissima', 'code' => '000000',
    ])->assertStatus(401);

    // Con il codice buono, si entra.
    $this->postJson('/api/v1/auth/login', [
        'login' => 'gestore',
        'password' => 'segretissima',
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertOk();
});

it('blocca le rotte sensibili a chi deve avere la MFA e non ce l\'ha', function () {
    // L'interruttore e' nel database, non nel .env: l'admin lo accende e lo spegne a
    // sistema acceso, senza deploy.
    PlatformSetting::set('security.require_mfa', true);

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/platform/settings')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'mfa_enrollment_required');

    // Ma la porta per configurarla resta aperta, altrimenti resterebbe chiuso fuori.
    $this->actingAs($admin)
        ->postJson('/api/v1/auth/mfa/enroll')
        ->assertOk()
        ->assertJsonStructure(['secret', 'otpauth_uri', 'recovery_codes']);
});

it('lascia passare quando la MFA e\' spenta dall\'admin', function () {
    PlatformSetting::set('security.require_mfa', false);

    $admin = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/platform/settings')
        ->assertOk();

    expect($response->json('settings'))->toBe(['security.require_mfa' => false]);
});
