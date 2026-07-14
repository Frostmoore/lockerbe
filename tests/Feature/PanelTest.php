<?php

use App\Domain\Tenancy\Middleware\ResolveTenant;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\CabinetResource\Pages\ListCabinets;
use App\Filament\Resources\LockerResource\Pages\ListLockers;
use App\Filament\Resources\SessionResource;
use App\Filament\Resources\TenantResource;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Locker;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create();
    $this->altro = Tenant::factory()->create();

    $this->gestore = User::factory()->forTenant($this->tenant)->create();
    $this->gestore->assignRole('tenant_admin');

    $this->staff = User::factory()->forTenant($this->tenant)->create();
    $this->staff->assignRole('tenant_staff');

    $this->admin = User::factory()->platformAdmin()->create();
    $this->admin->assignRole('platform_admin');

    $this->mio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['code' => 'MIO']);
    $this->suo = Cabinet::factory()->forTenant($this->altro)->online()->create(['code' => 'SUO']);
});

/**
 * Entra nel pannello del locale come utente di quel locale.
 *
 * ⚠️ `setTenant` a mano: nei test `livewire()` monta il componente **in-process**, senza
 * passare da nessun middleware. Nella vita vera quel contesto lo imposta `ResolveTenant`
 * (vedi i test HTTP piu' sotto, che invece i middleware li attraversano davvero).
 */
function entraNelLocale(User $utente, Tenant $tenant): void
{
    Filament::setCurrentPanel('app');
    test()->actingAs($utente);
    app(TenantContext::class)->setTenant($tenant->id);
}

/*
 * ═══ LA PORTA: chi entra in quale pannello ═══
 */

it('⚠️ non lascia entrare un utente di un locale nel pannello di piattaforma', function () {
    // ⚠️ IL TEST PIU' IMPORTANTE DI QUESTA FASE. Il pannello /admin gira **in bypass**: chi
    // ci entra vede i dati di TUTTI i clienti. `canAccessPanel()` e' l'unica cosa che sta in
    // mezzo — e non e' un pezzo del parco clienti che trapelerebbe: e' tutto.
    //
    // ⚠️ Da v8.3.1 la risposta non e' piu' un 403 ma un **rimbalzo sul suo pannello**: chi e'
    // gia' autenticato e ha un pannello legittimo non deve sbattere contro un muro
    // (`RedirectToOwnPanel`). Il confine e' identico — cambia solo come viene detto.
    //
    // ⚠️⚠️ Ma qui non basta verificare il redirect: bisogna verificare che **niente del
    // pannello di piattaforma sia finito nel corpo della risposta**. Un rimbalzo che rendesse
    // mezza pagina prima di rimbalzare sarebbe mezza pagina con gli armadi di tutti i clienti
    // — e il test, guardando solo lo status, direbbe che va tutto bene.
    $risposta = $this->actingAs($this->gestore)->get('/admin');

    $risposta->assertRedirect('/app');
    expect($risposta->getContent())->not->toContain('Locali');   // la voce di menu di /admin
});

it('non lascia entrare un platform_admin nel pannello dei locali', function () {
    // Non e' simmetria per bellezza: un platform_admin dentro /app girerebbe in bypass, e
    // vedrebbe la lista "del suo locale" popolata con gli armadi di tutti.
    $this->actingAs($this->admin)->get('/app')->assertRedirect('/admin');
});

it('non lascia entrare un account disabilitato', function () {
    $this->gestore->forceFill(['status' => 'disabled'])->save();

    // Disabilitare un account deve avere effetto sui pannelli, non solo sulle API.
    $this->actingAs($this->gestore)->get('/app')->assertForbidden();
});

it('lascia entrare ciascuno a casa propria', function () {
    $this->actingAs($this->gestore)->get('/app')->assertOk();
    $this->actingAs($this->admin)->get('/admin')->assertOk();
});

/*
 * ═══ L'ISOLAMENTO DENTRO IL PANNELLO ═══
 */

it('⚠️ mostra al gestore SOLO gli armadi del proprio locale', function () {
    // Richiesta HTTP vera: attraversa i middleware, quindi e' ResolveTenant a stringere il
    // contesto — non il test.
    $this->actingAs($this->gestore)
        ->get('/app/cabinets')
        ->assertOk()
        ->assertSee('MIO')
        ->assertDontSee('SUO');
});

it('⚠️⚠️ tiene ResolveTenant fra i middleware PERSISTENTI di Livewire', function () {
    /*
     * ⚠️ LA TRAPPOLA DI FILAMENT, ED E' GROSSA.
     *
     * Filament e' fatto di Livewire, e una richiesta Livewire **non ripercorre i middleware
     * della rotta**: ripercorre solo quelli dichiarati *persistenti*. Filament persiste
     * `Authenticate` da solo — quindi l'utente resta autenticato e sembra tutto a posto — ma
     * `ResolveTenant`, senza `isPersistent: true`, NON verrebbe rieseguito. Il contesto
     * resterebbe quello aperto da `EstablishTenantContext`: **bypass**. Nessun filtro.
     *
     * Concretamente: la pagina si carica giusta, poi al primo click su una colonna per
     * ordinare — che e' una richiesta Livewire — la tabella si ripopola **con gli armadi di
     * tutti i clienti**. Ordinare una tabella non deve far trapelare i dati di un altro locale.
     *
     * ⚠️ Il test e' STRUTTURALE, ed e' una scelta: montare una vera richiesta a
     * `/livewire/update` da un test significherebbe ricostruire a mano lo snapshot firmato di
     * Livewire — un test che verifica piu' il nostro finto snapshot che il sistema. Qui si
     * verifica la sola cosa da cui dipende tutto: che quel middleware sia nell'elenco.
     */
    expect(Livewire::getPersistentMiddleware())->toContain(ResolveTenant::class);
});

it('mostra al platform_admin gli armadi di tutti i locali', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    // E' il mestiere di quel pannello. Se questo fallisse, il supporto non potrebbe aprire un
    // vano per conto di un cliente al telefono.
    livewire(ListCabinets::class)->assertCanSeeTableRecords([$this->mio, $this->suo]);
});

/*
 * ═══ open-all: NASCONDERE UN BOTTONE NON E' SICUREZZA ═══
 */

it('⚠️ non mostra "Apri tutti" allo staff', function () {
    entraNelLocale($this->staff, $this->tenant);

    livewire(ListCabinets::class)->assertTableActionHidden('apriTutti', $this->mio);
});

it('⚠️⚠️ e l\'endpoint sottostante lo rifiuta lo stesso', function () {
    /*
     * ⚠️ E' IL TEST CHE VALE DAVVERO. Il bottone nascosto e' cortesia verso l'utente; la
     * sicurezza e' che l'azione **non parta** quando qualcuno la invoca comunque — cosa che da
     * una pagina web si fa con una riga di JavaScript, o con un curl.
     *
     * `locker.open_all` svuota un guardaroba. Non e' di `tenant_staff`, e non deve esserlo
     * nemmeno per un istante.
     */
    expect($this->staff->can('openAll', $this->mio))->toBeFalse();

    $this->actingAs($this->staff)
        ->postJson("/api/v1/cabinets/{$this->mio->id}/open-all", [
            'confirm' => true,
            'reason' => 'ci provo lo stesso',
        ])
        ->assertForbidden();

    expect(Command::query()->count())->toBe(0);
});

it('lascia al gestore aprire tutti i vani, con motivo e conferma', function () {
    entraNelLocale($this->gestore, $this->tenant);

    Locker::factory()->forCabinet($this->mio)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);
    Locker::factory()->forCabinet($this->mio)->create(['number' => 2, 'board_address' => 1, 'channel' => 2]);

    // ⚠️ Un vano fuori servizio non si apre nemmeno con open-all: la serratura e' rotta, e un
    // comando verso una serratura rotta e' solo un comando che fallira'.
    Locker::factory()->forCabinet($this->mio)->create([
        'number' => 3, 'board_address' => 1, 'channel' => 3, 'status' => 'out_of_service',
    ]);

    livewire(ListCabinets::class)
        ->callTableAction('apriTutti', $this->mio, [
            'reason' => 'evacuazione: allarme antincendio',
            'confirm' => true,
        ])
        ->assertHasNoTableActionErrors();

    // Due comandi, non tre. E ognuno con la SUA scadenza e la SUA firma.
    expect($this->mio->commands()->count())->toBe(2);

    // ⚠️ E la motivazione e' finita nel registro, insieme a chi l'ha scritta. Prima dei
    // comandi, non dopo: se il registro non riesce a scrivere, non si apre niente.
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'cabinet.open_all',
        'actor_id' => $this->gestore->id,
    ]);
});

it('⚠️ non crea NESSUN comando se l\'armadio e\' offline', function () {
    entraNelLocale($this->gestore, $this->tenant);

    $offline = Cabinet::factory()->forTenant($this->tenant)->create(['status' => 'offline']);
    $vano = Locker::factory()->forCabinet($offline)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    // ⚠️ La difesa piu' importante del sistema, esercitata dal pannello: un'apertura accodata
    // verso un armadio spento verrebbe consegnata ore dopo — aprendo un vano pieno di roba, di
    // notte, davanti a nessuno.
    livewire(ListLockers::class)->callTableAction('apri', $vano);

    expect(Command::query()->count())->toBe(0);
});

/*
 * ═══ I LOCALI: solo noi ═══
 */

it('⚠️ non registra la risorsa "locali" nel pannello dei locali', function () {
    expect(Filament::getPanel('app')->getResources())->not->toContain(TenantResource::class)
        ->and(Filament::getPanel('admin')->getResources())->toContain(TenantResource::class);
});

it('⚠️ e comunque la policy dice no a chi non e\' platform_admin', function () {
    // Registrare la risorsa in un solo pannello e' organizzazione, non sicurezza: la policy
    // deve dire no lo stesso. Un tenant_admin che potesse creare tenant si fabbricherebbe il
    // contenitore in cui mettere qualcosa che non e' suo.
    expect($this->gestore->can('viewAny', Tenant::class))->toBeFalse()
        ->and($this->gestore->can('create', Tenant::class))->toBeFalse()
        ->and($this->admin->can('viewAny', Tenant::class))->toBeTrue();
});

/*
 * ═══ CIO' CHE NON SI TOCCA ═══
 */

it('⚠️ non offre nessun modo di scrivere nel registro, nei comandi, nelle sessioni', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    /*
     * ⚠️ QUESTO TEST HA TROVATO UN BUCO VERO.
     *
     * Filament, davanti a una policy **priva del metodo**, di suo **autorizza**: era pronto a
     * offrire "crea" ed "elimina" sul registro di audit — la tabella che esiste apposta perche'
     * nessuno possa far sparire cio' che e' successo. Postgres avrebbe comunque rifiutato la
     * scrittura (UPDATE e DELETE sono revocati a `locker_app`), ma un pannello che offre un
     * bottone destinato a esplodere insegna che il sistema e' rotto, invece che rigoroso.
     *
     * Risolto in due modi, e servono entrambi: `strictAuthorization()` sui pannelli (un metodo
     * mancante diventa un'eccezione rumorosa, non un permesso silenzioso) e i dinieghi scritti
     * a mano nelle policy.
     */
    // ⚠️ Il registro e i comandi non sono piu' nemmeno delle *risorse*: il primo e' una pagina
    // di sola lettura, i secondi vivono dentro l'armadio. Ma le policy restano — e sono loro la
    // difesa: una risorsa la si puo' riaggiungere in un pomeriggio, una policy che dice `false`
    // continua a dire `false`.
    expect(SessionResource::canCreate())->toBeFalse()
        ->and($this->admin->can('create', AuditLog::class))->toBeFalse()
        ->and($this->admin->can('update', new AuditLog))->toBeFalse()
        ->and($this->admin->can('delete', new AuditLog))->toBeFalse()
        ->and($this->admin->can('update', new Session))->toBeFalse()
        ->and($this->admin->can('create', Command::class))->toBeFalse();
});

/*
 * ═══ MFA ═══
 */

it('⚠️ dirotta sulla pagina MFA chi deve il secondo fattore e non ce l\'ha', function () {
    PlatformSetting::set('security.require_mfa', true);

    // ⚠️ Non blocca il LOGIN: blocca il *fare cose*. Bloccare il login chiuderebbe fuori
    // chiunque debba ancora arruolarsi — cioe' tutti, il giorno in cui l'obbligo si accende.
    $this->actingAs($this->admin)
        ->get('/admin/cabinets')
        ->assertRedirect('/admin/mfa');

    // E la pagina che glielo fa configurare resta raggiungibile: senza, sarebbe un vicolo cieco.
    $this->actingAs($this->admin)->get('/admin/mfa')->assertOk();
});

it('non dirotta nessuno quando la MFA non e\' obbligatoria', function () {
    PlatformSetting::set('security.require_mfa', false);

    $this->actingAs($this->admin)->get('/admin/cabinets')->assertOk();
});
