<?php

use App\Domain\Command\Services\CommandSigner;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Device;
use App\Models\Locker;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create();

    $this->admin = User::factory()->forTenant($this->tenant)->create();
    $this->admin->assignRole('tenant_admin');

    $this->staff = User::factory()->forTenant($this->tenant)->create();
    $this->staff->assignRole('tenant_staff');

    // ⚠️ `online()`: heartbeat appena ricevuto. Senza, ogni apertura risponde 409 — ed e'
    // giusto cosi'.
    $this->cabinet = Cabinet::factory()->forTenant($this->tenant)->online()->create();
    $this->device = Device::factory()->forCabinet($this->cabinet)->create();

    $this->locker = Locker::factory()->forCabinet($this->cabinet)->create([
        'number' => 1, 'board_address' => 1, 'channel' => 1,
    ]);
});

function apri(object $test, ?string $key = null): TestResponse
{
    return $test->actingAs($test->admin)
        ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid7())
        ->postJson("/api/v1/lockers/{$test->locker->id}/open");
}

/*
 * F4 — I COMANDI. Il rischio #1 del sistema (piano §8).
 */

it('apre un vano e restituisce 202, non 200', function () {
    $r = apri($this)->assertStatus(202);

    $command = Command::query()->findOrFail($r->json('data.id'));

    // ⚠️ 202 = "preso in carico", NON "aperto". L'esito vero arriva con l'ack del device.
    // Leggere il 202 come una conferma di apertura, in un sistema di armadietti, costa un
    // cappotto.
    expect($command->status)->toBe('pending')
        ->and($command->type)->toBe('open')
        ->and($r->json('data.deliverable'))->toBeTrue();
});

it('mette una scadenza a OGNI comando', function () {
    $r = apri($this);

    $command = Command::query()->findOrFail($r->json('data.id'));
    $ttl = (int) config('locker.command.ttl_open');

    // ⚠️ Il TTL non e' una feature da aggiungere "quando ci sara' tempo": e' il motivo per cui
    // un `open` non puo' sopravvivere al proprio senso.
    expect($command->expires_at->diffInSeconds(now()))->toBeLessThanOrEqual($ttl + 1)
        ->and($command->expires_at->isFuture())->toBeTrue();
});

it('§17.3 — armadio OFFLINE ⇒ 409 e NESSUN comando accodato', function () {
    // Heartbeat di tre ore fa: l'armadio non risponde.
    $this->cabinet->forceFill(['last_seen_at' => now()->subHours(3)])->save();

    apri($this)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'device_offline');

    // ⚠️ IL PUNTO DI TUTTA LA FASE: non si accoda una promessa di apertura. Un comando che
    // esiste e' un comando che prima o poi puo' partire — e "prima o poi" puo' voler dire le 4
    // del mattino, davanti a un vano pieno di roba e a nessuno.
    expect(Command::query()->count())->toBe(0);
});

it('§17.2 — un comando scaduto diventa `expired` e non e\' piu\' consegnabile', function () {
    $r = apri($this);
    $command = Command::query()->findOrFail($r->json('data.id'));

    $command->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->artisan('commands:expire-stale')->assertSuccessful();

    $command->refresh();

    expect($command->status)->toBe('expired')
        ->and($command->isDeliverable())->toBeFalse();
});

it('§17.2 — un ack fuori tempo massimo NON resuscita un comando scaduto', function () {
    $r = apri($this);
    $command = Command::query()->findOrFail($r->json('data.id'));

    $command->forceFill(['expires_at' => now()->subMinute(), 'status' => 'expired'])->save();

    // Il device risponde in ritardo. ⚠️ Accettare l'ack significherebbe rimettere in gioco
    // proprio cio' che il TTL serviva a evitare.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/mock/commands/{$command->id}/ack", ['ok' => true]);

    expect($command->refresh()->status)->toBe('expired')
        ->and($command->acked_at)->toBeNull();
});

it('§17.4 — la stessa Idempotency-Key NON apre il vano due volte', function () {
    $key = (string) Str::uuid7();

    $primo = apri($this, $key)->assertStatus(202);
    $secondo = apri($this, $key)->assertStatus(202);

    // ⚠️ Stessa chiave ⇒ stesso comando. Un retry di rete, un doppio click, un webhook
    // consegnato due volte: nessuno di questi deve produrre una seconda apertura.
    expect($secondo->json('data.id'))->toBe($primo->json('data.id'))
        ->and(Command::query()->count())->toBe(1);
});

it('rifiuta l\'apertura senza Idempotency-Key', function () {
    // ⚠️ Il server NON la genera al posto del client: due retry produrrebbero due chiavi
    // diverse, quindi due comandi, quindi due aperture. Solo il client sa che sta ritentando
    // la STESSA richiesta.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/lockers/{$this->locker->id}/open")
        ->assertStatus(422);

    expect(Command::query()->count())->toBe(0);
});

it('firma ogni comando con la chiave del device', function () {
    $this->device->forceFill(['signing_secret' => 'segreto-di-questo-device'])->save();

    $r = apri($this);
    $command = Command::query()->findOrFail($r->json('data.id'));

    expect($command->signature)->not->toBeNull();

    // La firma copre anche `expires_at`: non si puo' rigiocare un comando vecchio cambiandogli
    // la scadenza, perche' la firma non tornerebbe piu'.
    $signer = app(CommandSigner::class);

    expect($signer->verify($command, $this->device->refresh(), (string) $command->signature))->toBeTrue();

    $command->forceFill(['expires_at' => now()->addHour()])->save();

    expect($signer->verify($command, $this->device, (string) $command->signature))->toBeFalse();
});

it('non espone la firma nelle risposte HTTP', function () {
    $r = apri($this);

    // Serve al device, non a un client HTTP. Cio' che non si espone non si perde.
    expect($r->json('data'))->not->toHaveKey('signature');
});

it('chiude il cerchio con l\'ack del device', function () {
    $r = apri($this);
    $commandId = $r->json('data.id');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/mock/commands/{$commandId}/ack", ['ok' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'acked');

    $this->actingAs($this->admin)
        ->getJson("/api/v1/commands/{$commandId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'acked')
        ->assertJsonPath('data.deliverable', false);
});

it('registra un ack negativo come `failed`', function () {
    $r = apri($this);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/mock/commands/{$r->json('data.id')}/ack", [
            'ok' => false, 'error' => 'serratura_inceppata',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'failed');
});

/*
 * ═══ APERTURA DI MASSA — l'azione piu' pericolosa del sistema ═══
 */

it('nega a tenant_staff l\'apertura di massa', function () {
    // Svuota il guardaroba. Non e' operativita': resta al gestore, che ne risponde.
    $this->actingAs($this->staff)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/open-all", [
            'confirm' => true, 'reason' => 'Allarme antincendio',
        ])
        ->assertForbidden();
});

it('esige conferma esplicita e motivazione per l\'apertura di massa', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/open-all", ['reason' => 'boh'])
        ->assertStatus(422);

    // Non ci si arriva per sbaglio cliccando in giro.
    expect(Command::query()->count())->toBe(0);
});

it('apre tutti i vani tranne quelli fuori servizio, e lo scrive nell\'audit', function () {
    Locker::factory()->forCabinet($this->cabinet)->create(['number' => 2, 'board_address' => 1, 'channel' => 2]);
    Locker::factory()->forCabinet($this->cabinet)->outOfService()->create([
        'number' => 3, 'board_address' => 1, 'channel' => 3,
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/cabinets/{$this->cabinet->id}/open-all", [
            'confirm' => true,
            'reason' => 'Allarme antincendio: evacuazione',
        ])
        ->assertStatus(202)
        ->assertJsonPath('lockers', 2);   // il vano guasto resta chiuso

    expect(Command::query()->count())->toBe(2);

    $audit = DB::table('audit_logs')
        ->where('action', 'cabinet.open_all')
        ->first();

    expect($audit)->not->toBeNull()
        ->and(json_decode((string) $audit->context, true)['reason'])
        ->toBe('Allarme antincendio: evacuazione');
});

/*
 * ═══ IL PAGAMENTO CON L'ARMADIO OFFLINE — l'unico caso in cui non si puo' fallire ═══
 */

it('attiva comunque la sessione se l\'armadio muore fra il pagamento e la conferma', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id])
        ->assertCreated();

    // L'armadio muore proprio adesso. I soldi, pero', sono gia' stati presi.
    $this->cabinet->forceFill(['last_seen_at' => now()->subHours(3)])->save();

    $this->actingAs($this->staff)
        ->postJson("/api/v1/mock/payments/{$created->json('payment.id')}/confirm")
        ->assertOk()
        // ⚠️ La sessione diventa comunque attiva: il cliente ha pagato, il vano e' suo.
        // Rifiutare qui lascerebbe un pagamento incassato senza sessione — il peggio dei due
        // mondi.
        ->assertJsonPath('data.status', 'active');

    // ⚠️ Ma NESSUN comando viene accodato: la difesa §8.4 resta intatta.
    expect(Command::query()->count())->toBe(0);

    $fallimento = DB::table('audit_logs')
        ->where('action', 'session.store_open_failed')
        ->first();

    expect($fallimento)->not->toBeNull();
});

it('emette comandi VERI dal flusso di sessione (il dispatcher e\' cambiato)', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/mock/payments/{$created->json('payment.id')}/confirm")
        ->assertOk();

    // ⚠️ In F3 questo era un finto dispatcher che non mandava niente. Ora e' un comando vero,
    // con TTL, firma e idempotenza — e SessionManager non se n'e' accorto: e' cambiata una riga
    // nel container.
    $command = Command::query()->firstOrFail();

    expect($command->type)->toBe('open')
        ->and($command->reason)->toBe('store')
        ->and($command->session_id)->toBe($created->json('data.id'))
        ->and($command->expires_at->isFuture())->toBeTrue();
});

it('rifiuta la riapertura quando l\'armadio e\' offline, senza toccare la sessione', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/mock/payments/{$created->json('payment.id')}/confirm");

    $this->cabinet->forceFill(['last_seen_at' => now()->subHours(3)])->save();

    // Qui, a differenza del pagamento, non c'e' niente di irreversibile in ballo: si fallisce
    // pulito e non si tocca nulla.
    $this->actingAs($this->staff)
        ->postJson("/api/v1/sessions/{$created->json('data.id')}/checkout")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'device_offline');

    expect(Session::query()->findOrFail($created->json('data.id'))->status)->toBe('active');
});

it('l\'heartbeat mock rimette online l\'armadio', function () {
    $this->cabinet->forceFill(['last_seen_at' => now()->subHours(3), 'status' => 'offline'])->save();

    apri($this)->assertStatus(409);

    // 💓 Il primo bottone da premere. Senza, il sistema sembra rotto — e invece e' la difesa
    // che fa il suo mestiere.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/mock/devices/{$this->cabinet->id}/heartbeat")
        ->assertOk()
        ->assertJsonPath('data.online', true);

    apri($this)->assertStatus(202);
});

it('non lascia aprire i vani di un altro locale', function () {
    $altro = Tenant::factory()->create();
    $cabinetAltrui = Cabinet::factory()->forTenant($altro)->online()->create();
    $lockerAltrui = Locker::factory()->forCabinet($cabinetAltrui)->create([
        'number' => 1, 'board_address' => 1, 'channel' => 1,
    ]);

    $this->actingAs($this->admin)
        ->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson("/api/v1/lockers/{$lockerAltrui->id}/open")
        ->assertNotFound();

    expect(Command::query()->count())->toBe(0);
});
