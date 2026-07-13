<?php

use App\Models\Cabinet;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->forTenant($this->tenant)->create();
    $this->admin->assignRole('tenant_admin');

    $this->staff = User::factory()->forTenant($this->tenant)->create();
    $this->staff->assignRole('tenant_staff');
});

/*
 * L'identita' del chiosco, nel flusso vero:
 *
 *   1. l'armadio arriva (lamiera + serrature + FCV5003 avvitato in mezzo: UN oggetto)
 *   2. il tecnico registra il dispositivo sul server, col serial letto dall'etichetta
 *   3. il tecnico crea l'armadio e lo lega a quel dispositivo
 *   4. il tecnico preme Attiva -> il chiosco acceso ritira le credenziali
 *   5. fine
 */

it('percorre il setup completo: registra → crea armadio → attiva → il chiosco si prende le credenziali', function () {
    // 2. Il tecnico registra il dispositivo.
    $device = $this->actingAs($this->admin)
        ->postJson('/api/v1/devices', ['serial' => 'FCV5003-0001', 'model' => 'VF203_V12'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'registered')
        ->json('data');

    // 3. Crea l'armadio, gia' legato a quel dispositivo. Un oggetto solo, un'operazione sola.
    $this->actingAs($this->admin)
        ->postJson('/api/v1/cabinets', [
            'name' => 'Guardaroba Ingresso',
            'code' => 'G1',
            'lockers' => 12,
            'device_id' => $device['id'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.device.serial', 'FCV5003-0001');

    // 4. Il tecnico preme Attiva: si apre la finestra.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/devices/{$device['id']}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', 'provisioned');

    // Il chiosco si accende e ritira le credenziali. Non e' autenticato — non ha ancora nulla
    // da esibire — ma non e' nemmeno un ignoto: il server sa gia' chi e' quel serial.
    $credenziali = $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0001'])
        ->assertOk()
        ->json('credentials');

    $model = Device::query()->where('serial', 'FCV5003-0001')->firstOrFail();

    expect($credenziali['mqtt_secret'])->toBeString()
        // Sul server resta solo l'IMPRONTA del segreto, mai il segreto.
        ->and($model->credential_fingerprint)->toBe(hash('sha256', $credenziali['mqtt_secret']))
        ->and($credenziali['cabinet_id'])->toBe($model->cabinet_id);
});

it('non consegna le credenziali fuori dalla finestra di attivazione', function () {
    $device = registraChiosco($this, 'FCV5003-0002');

    // ⚠️ Nessuno ha premuto "Attiva": il chiosco (o chi si spaccia per lui) non ottiene niente.
    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0002'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'activation_closed');
});

it('chiude la finestra quando scade', function () {
    $device = registraChiosco($this, 'FCV5003-0003');

    $this->actingAs($this->admin)->postJson("/api/v1/devices/{$device->id}/activate")->assertOk();

    $device->refresh()->forceFill(['activation_expires_at' => now()->subMinute()])->save();

    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0003'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'activation_closed');
});

it('non consegna le credenziali due volte con la stessa attivazione', function () {
    $device = registraChiosco($this, 'FCV5003-0004');

    $this->actingAs($this->admin)->postJson("/api/v1/devices/{$device->id}/activate");

    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0004'])->assertOk();

    // Il ritiro chiude la finestra: serve una nuova attivazione.
    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0004'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'activation_closed');
});

it('non dice niente su un serial che nessuno ha registrato', function () {
    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-FANTASMA'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'unknown_device');
});

/*
 * ═══ IL CALO DI CORRENTE, E LA MEMORIA AZZERATA ═══
 */

it('ri-abilita il chiosco con lo STESSO bottone: un solo gesto da imparare', function () {
    $device = registraChiosco($this, 'FCV5003-0005');

    $this->actingAs($this->admin)->postJson("/api/v1/devices/{$device->id}/activate");
    $prime = $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0005'])->json('credentials');

    // ⚡ Reflash / factory reset / OTA finito male: il chiosco ha perso tutto e ribussa.
    // Fuori dalla finestra: non ottiene niente. Il server non ri-fida nessuno da solo — non
    // puo' distinguere il chiosco vero da un impostore che conosce il serial.
    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0005'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'activation_closed');

    // Il tecnico preme "Attiva". LO STESSO BOTTONE della prima installazione.
    $this->actingAs($this->admin)->postJson("/api/v1/devices/{$device->id}/activate")->assertOk();

    $nuove = $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0005'])
        ->assertOk()
        ->json('credentials');

    // Segreto nuovo, ma STESSO armadio: l'accoppiamento non si perde.
    expect($nuove['mqtt_secret'])->not->toBe($prime['mqtt_secret'])
        ->and($nuove['cabinet_id'])->toBe($prime['cabinet_id']);
});

it('non tocca le credenziali valide quando qualcuno bussa fuori dalla finestra', function () {
    $device = registraChiosco($this, 'FCV5003-0006');

    $this->actingAs($this->admin)->postJson("/api/v1/devices/{$device->id}/activate");
    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0006'])->assertOk();

    $improntaValida = $device->refresh()->credential_fingerprint;

    // ⚠️ Un impostore che conosce il serial bussa. Non deve poter buttare fuori il chiosco vero
    // semplicemente bussando: le credenziali in uso restano intatte.
    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0006'])->assertStatus(409);

    expect($device->refresh()->credential_fingerprint)->toBe($improntaValida);
});

/*
 * ═══ AUTORIZZAZIONE E ISOLAMENTO ═══
 */

it('nega a tenant_staff la registrazione e l\'attivazione di un chiosco', function () {
    $this->actingAs($this->staff)
        ->postJson('/api/v1/devices', ['serial' => 'FCV5003-0007'])
        ->assertForbidden();

    $device = registraChiosco($this, 'FCV5003-0008');

    $this->actingAs($this->staff)
        ->postJson("/api/v1/devices/{$device->id}/activate")
        ->assertForbidden();
});

it('non lascia vedere i chioschi di un altro locale', function () {
    $altro = Tenant::factory()->create();
    $cabinetAltrui = Cabinet::factory()->forTenant($altro)->create();
    Device::factory()->forCabinet($cabinetAltrui)->create(['serial' => 'FCV5003-ALTRUI']);

    registraChiosco($this, 'FCV5003-0009');

    $seriali = collect($this->actingAs($this->admin)->getJson('/api/v1/devices')->json('data'))
        ->pluck('serial');

    expect($seriali)->toContain('FCV5003-0009')
        ->and($seriali)->not->toContain('FCV5003-ALTRUI');
});

it('revoca un chiosco e ne cancella le credenziali', function () {
    $device = registraChiosco($this, 'FCV5003-0010');

    $this->actingAs($this->admin)->postJson("/api/v1/devices/{$device->id}/activate");
    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-0010']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/devices/{$device->id}/revoke", ['reason' => 'Chiosco rubato'])
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    expect($device->refresh()->credential_fingerprint)->toBeNull();

    // Un chiosco revocato non si riattiva: si sostituisce.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/devices/{$device->id}/activate")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'device_revoked');
});

it('non attiva un dispositivo che non e\' ancora legato a un armadio', function () {
    $device = $this->actingAs($this->admin)
        ->postJson('/api/v1/devices', ['serial' => 'FCV5003-0011'])
        ->json('data');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/devices/{$device['id']}/activate")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'device_without_cabinet');
});

/** Registra il chiosco e crea il suo armadio: i passi 2 e 3 del setup. */
function registraChiosco(object $test, string $serial): Device
{
    $device = $test->actingAs($test->admin)
        ->postJson('/api/v1/devices', ['serial' => $serial])
        ->json('data');

    $test->actingAs($test->admin)->postJson('/api/v1/cabinets', [
        'name' => 'Armadio '.$serial,
        'code' => substr($serial, -4),
        'lockers' => 8,
        'device_id' => $device['id'],
    ]);

    return Device::query()->where('serial', $serial)->firstOrFail();
}
