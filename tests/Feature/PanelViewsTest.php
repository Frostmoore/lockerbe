<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Registro;
use App\Filament\Resources\CabinetResource\Pages\NodiCabinet;
use App\Filament\Resources\TenantResource\Pages\DettaglioTenant;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Device;
use App\Models\Locker;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create(['name' => 'Il Mio Locale']);
    $this->altro = Tenant::factory()->create(['name' => 'Locale Altrui']);

    $this->gestore = User::factory()->forTenant($this->tenant)->create();
    $this->gestore->assignRole('tenant_admin');

    $this->admin = User::factory()->platformAdmin()->create();
    $this->admin->assignRole('platform_admin');

    $this->mio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['code' => 'MIO']);
    $this->suo = Cabinet::factory()->forTenant($this->altro)->online()->create(['code' => 'SUO']);

    $this->vano = Locker::factory()->forCabinet($this->mio)->create([
        'number' => 1, 'board_address' => 1, 'channel' => 1,
    ]);
});

/*
 * ═══ LA HOME: un cartellino per armadio, raggruppati per locale ═══
 */

it('⚠️ mostra al gestore solo i cartellini del proprio locale', function () {
    // ⚠️ Non c'e' nessun filtro nella pagina: lo fa il database. Se questo test fallisse,
    // vorrebbe dire che la home di un cliente mostra gli armadi di un altro.
    $this->actingAs($this->gestore)
        ->get('/app')
        ->assertOk()
        ->assertSee('MIO')
        ->assertDontSee('SUO');
});

it('mostra al platform_admin gli armadi di TUTTI i locali, raggruppati', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    // E' il mestiere di quel pannello: al telefono col cliente, si deve poter vedere il suo
    // armadio senza chiedergli un uuid.
    $locali = livewire(Dashboard::class)->instance()->locali();

    expect(array_keys($locali))->toContain('Il Mio Locale', 'Locale Altrui');
});

/*
 * ═══ LA VISTA A NODI ═══
 */

it('mostra il chiosco e i vani dell\'armadio', function () {
    Device::factory()->forCabinet($this->mio)->create(['serial' => 'FCV5003-0001']);

    $this->actingAs($this->gestore)
        ->get("/app/cabinets/{$this->mio->id}/nodi")
        ->assertOk()
        ->assertSee('FCV5003-0001')   // il nodo chiosco
        ->assertSee('libero');        // il nodo vano
});

it('⚠️ non lascia vedere la vista a nodi di un armadio altrui', function () {
    // ⚠️ Digitare l'indirizzo a mano non deve bastare. L'armadio altrui non e' nemmeno
    // visibile (RLS), quindi qui deve rompersi — non mostrare.
    $this->actingAs($this->gestore)
        ->get("/app/cabinets/{$this->suo->id}/nodi")
        ->assertNotFound();
});

it('mostra gli ordini di apertura dentro la pagina dell\'armadio', function () {
    Filament::setCurrentPanel('app');
    $this->actingAs($this->gestore);
    app(TenantContext::class)->setTenant($this->tenant->id);

    Command::factory()->forLocker($this->mio, $this->vano)->create();

    // ⚠️ I comandi non sono una voce di menu: nessuno si sveglia volendo "vedere i comandi".
    // Li si guarda quando un vano non si e' aperto — e allora si e' gia' qui, davanti a quel vano.
    $comandi = livewire(NodiCabinet::class, ['record' => $this->mio->id])->instance()->comandi();

    expect($comandi)->toHaveCount(1);
});

it('apre un vano dalla vista a nodi', function () {
    Filament::setCurrentPanel('app');
    $this->actingAs($this->gestore);
    app(TenantContext::class)->setTenant($this->tenant->id);

    livewire(NodiCabinet::class, ['record' => $this->mio->id])
        ->call('apri', $this->vano->id);

    expect(Command::query()->count())->toBe(1);
});

it('⚠️ non crea nessun comando se l\'armadio e\' offline', function () {
    Filament::setCurrentPanel('app');
    $this->actingAs($this->gestore);
    app(TenantContext::class)->setTenant($this->tenant->id);

    /*
     * ⚠️ Non basta mettere `status = 'offline'`: `Cabinet::isOnline()` NON guarda quella
     * colonna, guarda **l'ultimo battito**. Ed e' giusto cosi' — la colonna e' cio' che
     * *crediamo*, l'heartbeat e' cio' che *sappiamo* — ma vuol dire che un armadio "offline"
     * con un battito recente e' online, e questo test, scritto male, passava senza provare
     * niente.
     */
    $this->mio->forceFill(['status' => 'offline', 'last_seen_at' => null])->save();

    // ⚠️ La difesa piu' importante del sistema, esercitata anche da qui: un'apertura accodata
    // verso un armadio spento verrebbe consegnata ore dopo, su un vano pieno, davanti a nessuno.
    livewire(NodiCabinet::class, ['record' => $this->mio->refresh()->id])
        ->call('apri', $this->vano->id);

    expect(Command::query()->count())->toBe(0);
});

/*
 * ═══ IL REGISTRO: un log, non una tabella ═══
 */

it('scrive le righe del registro in italiano, con gli uuid gia\' risolti', function () {
    Filament::setCurrentPanel('app');
    $this->actingAs($this->gestore);
    app(TenantContext::class)->setTenant($this->tenant->id);

    app(AuditLogger::class)->log('cabinet.open_all', [
        'cabinet_id' => $this->mio->id,
        'actor' => $this->gestore,
        'context' => ['reason' => 'allarme antincendio', 'vani' => 1],
    ]);

    $righe = livewire(Registro::class)->instance()->righe();

    // ⚠️ Un registro fatto di uuid non lo rilegge nessuno, nemmeno chi l'ha scritto: qui
    // "019f5c…" e' gia' diventato "armadio MIO", e "cabinet.open_all" una frase che si legge.
    expect($righe->first()['frase'])->toContain('HA APERTO TUTTI I VANI')
        ->and($righe->first()['dove'])->toContain('armadio MIO')
        ->and($righe->first()['chi'])->toContain($this->gestore->username)
        ->and($righe->first()['contesto'])->toContain('allarme antincendio');
});

it('⚠️ non mostra al gestore le voci di registro di un altro locale', function () {
    $utenteAltrui = User::factory()->forTenant($this->altro)->create();

    app(TenantContext::class)->runForTenant(
        $this->altro->id,
        fn () => app(AuditLogger::class)->log('cabinet.open_all', [
            'cabinet_id' => $this->suo->id,
            'actor' => $utenteAltrui,
            'context' => ['reason' => 'segretissimo'],
        ]),
    );

    Filament::setCurrentPanel('app');
    $this->actingAs($this->gestore);
    app(TenantContext::class)->setTenant($this->tenant->id);

    $righe = livewire(Registro::class)->instance()->righe();

    // Il registro e' l'ultimo posto in cui vorremmo una fuga: ci finisce il PERCHE' delle cose.
    expect($righe)->toBeEmpty();
});

/*
 * ═══ IL DETTAGLIO DEL LOCALE ═══
 */

it('mostra gli armadi e i chioschi del locale — e SOLO i suoi', function () {
    Device::factory()->forCabinet($this->mio)->create(['serial' => 'FCV5003-MIO']);
    Device::factory()->forCabinet($this->suo)->create(['serial' => 'FCV5003-SUO']);

    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $pagina = livewire(DettaglioTenant::class, ['record' => $this->tenant->id])->instance();

    // ⚠️ Questa pagina gira in BYPASS (pannello di piattaforma): il tenant lo mette una `where`
    // esplicita. Senza, dentro la scheda di un cliente si vedrebbero gli armadi di tutti.
    expect($pagina->armadi()->pluck('code')->all())->toBe(['MIO'])
        ->and($pagina->chioschi()->pluck('serial')->all())->toBe(['FCV5003-MIO']);
});

/*
 * ═══ RESET PASSWORD ═══
 */

it('manda il link di reset password, senza rivelarla a nessuno', function () {
    Notification::fake();

    Filament::setCurrentPanel('app');
    $this->actingAs($this->gestore);
    app(TenantContext::class)->setTenant($this->tenant->id);

    $staff = User::factory()->forTenant($this->tenant)->create();
    $staff->assignRole('tenant_staff');

    livewire(ListUsers::class)
        ->callTableAction('resetPassword', $staff)
        ->assertHasNoTableActionErrors();

    // ⚠️ Un LINK, non una password scelta dall'admin. Un admin che digita la password di un
    // altro e' un admin che la conosce — e da quel momento nessuna riga dell'audit puo' piu'
    // dire con certezza CHI ha fatto una cosa con quell'account.
    Notification::assertSentTo($staff, ResetPassword::class);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'user.password_reset_sent',
        'actor_id' => $this->gestore->id,
    ]);
});
