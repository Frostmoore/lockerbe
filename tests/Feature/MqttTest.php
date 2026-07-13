<?php

use App\Domain\Mqtt\Topics;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Device;
use App\Models\Locker;
use App\Models\Tenant;
use App\Models\User;
use App\Mqtt\CommandPublisher;
use App\Mqtt\PublishCommandJob;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->forTenant($this->tenant)->create();
    $this->admin->assignRole('tenant_admin');

    $this->cabinet = Cabinet::factory()->forTenant($this->tenant)->online()->create();
    $this->device = Device::factory()->forCabinet($this->cabinet)->create([
        'mqtt_client_id' => 'dev-test-01',
        'credential_fingerprint' => hash('sha256', 'segreto-giusto'),
        'signing_secret' => 'segreto-giusto',
    ]);
    $this->locker = Locker::factory()->forCabinet($this->cabinet)->create([
        'number' => 1, 'board_address' => 1, 'channel' => 1,
    ]);
});

/*
 * ═══ LE ACL DEL BROKER: il confine tra clienti sul canale realtime (§3.3) ═══
 *
 * Il broker non tiene nessun elenco: chiede a noi. Questi test sono cio' che gli rispondiamo.
 */

it('lascia connettere un chiosco con le sue credenziali', function () {
    $this->postJson('/api/v1/mqtt/user', [
        'username' => 'dev-test-01',
        'password' => 'segreto-giusto',
    ])->assertOk();
});

it('rifiuta un chiosco con la password sbagliata', function () {
    $this->postJson('/api/v1/mqtt/user', [
        'username' => 'dev-test-01',
        'password' => 'segreto-sbagliato',
    ])->assertUnauthorized();
});

it('⚠️ rifiuta SUBITO un chiosco revocato', function () {
    $this->device->forceFill(['status' => 'revoked'])->save();

    // ⚠️ E' il motivo per cui il broker chiede a noi invece di leggere un file: la revoca ha
    // effetto **immediato**. Con un `passwd` statico, un chiosco rubato resterebbe dentro
    // finche' qualcuno non rigenera i file e ricarica il broker — cioe' "quando se ne ricorda".
    $this->postJson('/api/v1/mqtt/user', [
        'username' => 'dev-test-01',
        'password' => 'segreto-giusto',
    ])->assertUnauthorized();
});

it('non ammette NESSUN superuser', function () {
    // ⚠️ Un superuser MQTT scavalcherebbe **tutte** le ACL, cioe' l'intero confine tra clienti.
    // Non ne esistono, e non devono esistere.
    $this->postJson('/api/v1/mqtt/superuser', ['username' => 'locker-server'])
        ->assertUnauthorized();
});

it('lascia al chiosco solo i topic del PROPRIO armadio', function () {
    $mio = Topics::command($this->cabinet);

    // Sottoscrivere i propri comandi: si'.
    $this->postJson('/api/v1/mqtt/acl', [
        'username' => 'dev-test-01', 'topic' => $mio, 'acc' => 1,
    ])->assertOk();

    // Pubblicare i propri eventi: si'.
    $this->postJson('/api/v1/mqtt/acl', [
        'username' => 'dev-test-01', 'topic' => Topics::event($this->cabinet), 'acc' => 2,
    ])->assertOk();

    // ⚠️ Pubblicare sui PROPRI comandi: NO. Un chiosco che potesse scriversi i comandi da solo
    // aprirebbe i propri vani quando gli pare.
    $this->postJson('/api/v1/mqtt/acl', [
        'username' => 'dev-test-01', 'topic' => $mio, 'acc' => 2,
    ])->assertUnauthorized();
});

it('⚠️ non lascia a un chiosco i topic di un ALTRO armadio', function () {
    $altroTenant = Tenant::factory()->create();
    $altroCabinet = Cabinet::factory()->forTenant($altroTenant)->create();

    // ⚠️ IL TEST PIU' IMPORTANTE DI QUESTA FASE. Un chiosco che potesse sottoscrivere i comandi
    // di un altro armadio li **eseguirebbe**: aprirebbe gli armadietti di un altro locale.
    $this->postJson('/api/v1/mqtt/acl', [
        'username' => 'dev-test-01',
        'topic' => Topics::command($altroCabinet),
        'acc' => 1,
    ])->assertUnauthorized();
});

it('non lascia a un chiosco le wildcard', function () {
    // `locker/#` sarebbe l'intero sistema, di tutti i clienti, in un colpo solo.
    foreach (['locker/#', 'locker/t/+/cab/+/cmd', '#'] as $topic) {
        $this->postJson('/api/v1/mqtt/acl', [
            'username' => 'dev-test-01', 'topic' => $topic, 'acc' => 1,
        ])->assertUnauthorized();
    }
});

it('da\' al server permessi SPECULARI: pubblica comandi, ascolta eventi', function () {
    $server = (string) config('locker.mqtt.server_username');

    // Pubblica comandi: si'.
    $this->postJson('/api/v1/mqtt/acl', [
        'username' => $server, 'topic' => Topics::command($this->cabinet), 'acc' => 2,
    ])->assertOk();

    // Ascolta eventi: si'.
    $this->postJson('/api/v1/mqtt/acl', [
        'username' => $server, 'topic' => Topics::event($this->cabinet), 'acc' => 1,
    ])->assertOk();

    // ⚠️ Ma NON puo' pubblicare eventi: il server non deve poter fingersi un device e
    // raccontare che un vano si e' aperto quando non e' vero.
    $this->postJson('/api/v1/mqtt/acl', [
        'username' => $server, 'topic' => Topics::event($this->cabinet), 'acc' => 2,
    ])->assertUnauthorized();
});

/*
 * ═══ LA PUBBLICAZIONE ═══
 */

it('accoda la pubblicazione invece di farla dentro la richiesta HTTP', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson("/api/v1/lockers/{$this->locker->id}/open")
        ->assertStatus(202);

    // ⚠️ Non e' una scelta di stile: il broker, per autenticare chi si connette, **chiama il
    // nostro server**. Pubblicare dentro la richiesta significherebbe aspettare il broker, che
    // sta aspettando noi. Deadlock.
    Queue::assertPushed(PublishCommandJob::class);
});

it('⚠️ NON pubblica un comando gia\' scaduto, nemmeno se il job parte in ritardo', function () {
    $command = Command::factory()->forLocker($this->cabinet, $this->locker)->stale()->create();

    $publisher = Mockery::mock(CommandPublisher::class);
    $publisher->shouldNotReceive('publish');
    $this->app->instance(CommandPublisher::class, $publisher);

    // Il comando ha aspettato troppo in coda. ⚠️ E' ESATTAMENTE il comando che non deve
    // partire: consegnarlo adesso significherebbe aprire un vano fuori tempo, davanti a nessuno.
    (new PublishCommandJob($command->id, $this->tenant->id))
        ->handle(app(TenantContext::class), $publisher);
});

/*
 * ═══ IL CHIOSCO AUTENTICATO COME DEVICE ═══
 */

it('lascia al chiosco creare una sessione senza che il cliente abbia un account', function () {
    $token = $this->device->createToken('kiosk')->plainTextToken;

    $r = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/kiosk/sessions')
        ->assertCreated();

    // Il chiosco non ha scelto l'armadio: glielo dice la sua stessa identita'.
    expect($r->json('locker_number'))->toBe(1)
        ->and($r->json('payment.qr_svg'))->toStartWith('data:image/png;base64,');
});

it('⚠️ non lascia al chiosco toccare un armadio che non e\' il suo', function () {
    $token = $this->device->createToken('kiosk')->plainTextToken;

    $st = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/kiosk/state')
        ->assertOk();

    // L'armadio non e' un parametro: e' un fatto dell'identita' del chiosco. Un chiosco che
    // potesse indicare un `cabinet_id` a piacere sarebbe un chiosco che, compromesso, apre gli
    // armadi degli altri.
    expect($st->json('cabinet.code'))->toBe($this->cabinet->code);
});
