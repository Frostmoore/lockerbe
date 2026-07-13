<?php

use App\Models\Cabinet;
use App\Models\Device;
use App\Models\DeviceEnrollment;
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

    $this->cabinet = Cabinet::factory()->forTenant($this->tenant)->create(['code' => 'G1']);
});

/*
 * L'identita' del chiosco.
 *
 * Le due domande che questa fase risolve:
 *   1. come si assegna FISICAMENTE un chiosco a un armadio?
 *   2. dopo un calo di corrente (o un reflash), come sappiamo che e' sempre lui?
 */

it('accoppia il chiosco all\'armadio col codice mostrato a schermo', function () {
    // 1. Il chiosco si presenta. Non e' autenticato: non ha ancora un'identita' da esibire.
    $annuncio = $this->postJson('/api/v1/devices/announce', [
        'serial' => 'FCV5003-AAA-001',
        'model' => 'VF203_V12',
    ])->assertStatus(202);

    $codice = $annuncio->json('pairing_code');

    expect($codice)->toBeString()->toHaveLength(6);

    // 2. Il tecnico, DAVANTI a quell'armadio, digita il codice che legge su QUELLO schermo.
    // ⚠️ E' l'unico punto in cui si decide quale chiosco comanda quale armadio: nessun
    // automatismo puo' saperlo.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice])
        ->assertCreated()
        ->assertJsonPath('data.serial', 'FCV5003-AAA-001');

    // 3. Il chiosco ritira le credenziali. Una volta sola.
    $credenziali = $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-AAA-001'])
        ->assertOk()
        ->json('credentials');

    expect($credenziali['mqtt_client_id'])->toBeString()
        ->and($credenziali['mqtt_secret'])->toBeString()
        ->and($credenziali['cabinet_id'])->toBe($this->cabinet->id);

    // Sul server resta solo l'IMPRONTA del segreto, mai il segreto.
    $device = Device::query()->where('serial', 'FCV5003-AAA-001')->firstOrFail();

    expect($device->credential_fingerprint)->toBe(hash('sha256', $credenziali['mqtt_secret']))
        ->and($device->cabinet_id)->toBe($this->cabinet->id)
        ->and($device->paired_by)->toBe($this->admin->id);
});

it('non consegna le credenziali due volte', function () {
    $codice = $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-BBB-002'])
        ->json('pairing_code');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice]);

    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-BBB-002'])->assertOk();

    // ⚠️ Un secondo ritiro e' rifiutato: se il device le ha perse, deve passare da un umano.
    // Consegnarle a chiunque le richieda significherebbe consegnarle a chiunque conosca il serial.
    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-BBB-002'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'credentials_already_collected');
});

it('rifiuta un codice di accoppiamento scaduto', function () {
    $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-CCC-003']);

    $enrollment = DeviceEnrollment::query()->where('serial', 'FCV5003-CCC-003')->firstOrFail();
    $codice = $enrollment->pairing_code;

    $enrollment->forceFill(['pairing_code_expires_at' => now()->subMinute()])->save();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_pairing_code');
});

it('non accoppia un secondo chiosco a un armadio che ne ha gia\' uno', function () {
    $codice = $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-DDD-004'])
        ->json('pairing_code');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice])
        ->assertCreated();

    $codice2 = $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-EEE-005'])
        ->json('pairing_code');

    // Un armadio con due chioschi e' un armadio che riceve due volte gli stessi comandi.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice2])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'cabinet_already_paired');
});

it('nega a tenant_staff l\'accoppiamento di un chiosco', function () {
    $codice = $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-FFF-006'])
        ->json('pairing_code');

    // Decidere quale chiosco comanda quale armadio e' gestione, non operativita'.
    $this->actingAs($this->staff)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice])
        ->assertForbidden();
});

/*
 * ═══ LA SECONDA DOMANDA: dopo un calo di corrente, e' sempre lui? ═══
 */

it('non ri-fida da solo un serial gia\' accoppiato che si ripresenta', function () {
    $codice = $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-GGG-007'])
        ->json('pairing_code');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice]);

    $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-GGG-007'])->assertOk();

    $device = Device::query()->where('serial', 'FCV5003-GGG-007')->firstOrFail();
    $improntaOriginale = $device->credential_fingerprint;

    // ⚠️ Qualcuno si ripresenta con quel serial. Puo' essere il chiosco vero che ha perso la
    // memoria (reflash, factory reset, OTA finito male) — oppure un impostore che conosce il
    // serial. Il server NON PUO' distinguerli, quindi non ci prova.
    $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-GGG-007'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_paired');

    $device->refresh();

    // Nessuna credenziale nuova, e — soprattutto — ⚠️ **le vecchie restano valide**:
    // invalidarle qui darebbe a chiunque conosca un serial il potere di buttare fuori un
    // chiosco vero, semplicemente bussando.
    expect($device->credential_fingerprint)->toBe($improntaOriginale)
        ->and($device->needsReenrollment())->toBeTrue();   // ma lo staff lo vede
});

it('ri-abilita il chiosco solo su conferma di un umano', function () {
    $codice = $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-HHH-008'])
        ->json('pairing_code');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice]);

    $primeCredenziali = $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-HHH-008'])
        ->json('credentials');

    $device = Device::query()->where('serial', 'FCV5003-HHH-008')->firstOrFail();

    // L'operatore guarda il chiosco, riconosce che e' quello vero, e lo ri-abilita.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/devices/{$device->id}/reissue")
        ->assertOk();

    $nuoveCredenziali = $this->postJson('/api/v1/devices/credentials', ['serial' => 'FCV5003-HHH-008'])
        ->assertOk()
        ->json('credentials');

    // Il segreto e' nuovo, ma l'ARMADIO e' lo stesso: l'accoppiamento non si perde. E' il
    // motivo per cui l'identita' e' il serial (che sopravvive a un reflash) e non un uuid che
    // il device si inventa (che non ci sopravvive).
    expect($nuoveCredenziali['mqtt_secret'])->not->toBe($primeCredenziali['mqtt_secret'])
        ->and($nuoveCredenziali['cabinet_id'])->toBe($this->cabinet->id)
        ->and($device->refresh()->needsReenrollment())->toBeFalse();
});

it('revoca un chiosco rubato e ne cancella le credenziali', function () {
    $codice = $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-III-009'])
        ->json('pairing_code');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/pair", ['pairing_code' => $codice]);

    $device = Device::query()->where('serial', 'FCV5003-III-009')->firstOrFail();

    // ⚠️ La revoca e' la SOLA difesa reale: il FCV5003 non ha un secure element, quindi il
    // segreto nella sua memoria e' estraibile da chi ce l'ha in mano. Non ci si difende: ci si
    // accorge, e si revoca.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/devices/{$device->id}/revoke", ['reason' => 'Chiosco rubato dal locale'])
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    $device->refresh();

    expect($device->isRevoked())->toBeTrue()
        ->and($device->credential_fingerprint)->toBeNull();
});

it('non lascia accoppiare un chiosco all\'armadio di un altro locale', function () {
    $altro = Tenant::factory()->create();
    $cabinetAltrui = Cabinet::factory()->forTenant($altro)->create();

    $codice = $this->postJson('/api/v1/devices/announce', ['serial' => 'FCV5003-JJJ-010'])
        ->json('pairing_code');

    // L'armadio di un altro locale non esiste, per questo admin: 404.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$cabinetAltrui->id}/pair", ['pairing_code' => $codice])
        ->assertNotFound();
});
