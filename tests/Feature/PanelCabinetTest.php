<?php

/*
 * IL PANNELLO: creazione di un armadio e chi decide il prezzo.
 *
 * ⚠️ Due cose che "sembravano funzionare":
 *   1. il form chiedeva il prezzo alla creazione e lo BUTTAVA VIA (handleRecordCreation
 *      passava al service solo name e code). Nessun errore: solo un armadio che seguiva il
 *      listino del locale invece del prezzo appena scritto, scoperto alla prima cassa;
 *   2. il prezzo era modificabile dal gestore del locale — cioè da chi lo incassa.
 */

use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\CabinetResource\Pages\CreateCabinet;
use App\Filament\Resources\CabinetResource\Pages\EditCabinet;
use App\Models\Cabinet;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->locale = Tenant::factory()->create(['settings' => ['tariff_cents' => 500]]);

    $this->gestore = User::factory()->forTenant($this->locale)->create();
    $this->gestore->assignRole('tenant_admin');

    $this->piattaforma = User::factory()->create(['tenant_id' => null]);
    $this->piattaforma->assignRole('platform_admin');
});

/** Entra nel pannello dei locali come gestore, col contesto tenant armato. */
function comeGestore(): void
{
    Filament::setCurrentPanel('app');
    test()->actingAs(test()->gestore);
    app(TenantContext::class)->setTenant(test()->locale->id);
}

function comePiattaforma(): void
{
    Filament::setCurrentPanel('admin');
    test()->actingAs(test()->piattaforma);
    app(TenantContext::class)->bypass();
}

it('crea i vani insieme all\'armadio, con la mappa RS-485', function () {
    // ⚠️ `livewire()` monta il componente in-process e NON passa dai middleware (trappola 28):
    //    il pannello "corrente" non è quello che si crederebbe, e il selettore del locale
    //    risulta visibile e obbligatorio. Si dichiara il locale, invece di combattere il test.
    comePiattaforma();

    livewire(CreateCabinet::class)
        ->fillForm([
            'tenant_id' => $this->locale->id,
            'name' => 'Guardaroba platea',
            'code' => 'PLA',
            'locker_count' => 8,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $armadio = Cabinet::withoutGlobalScopes()->where('code', 'PLA')->firstOrFail();

    // ⚠️ Un armadio senza vani non apre niente; dei vani senza mappa RS-485 sono vani che il
    //    chiosco non sa dove andare a cercare.
    expect($armadio->lockers()->count())->toBe(8);
    expect($armadio->lockers()->whereNull('board_address')->orWhereNull('channel')->count())->toBe(0);
    expect($armadio->lockers()->pluck('number')->sort()->values()->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8]);
});

it('⚠️ NON butta via il prezzo scritto alla creazione', function () {
    // Il bug: `handleRecordCreation()` passava al service solo name e code. Il prezzo spariva
    // in silenzio e l'armadio ereditava quello del locale.
    comePiattaforma();

    livewire(CreateCabinet::class)
        ->fillForm([
            'tenant_id' => $this->locale->id,
            'name' => 'Guardaroba galleria',
            'code' => 'GAL',
            'locker_count' => 4,
            'tariff_cents' => '7.50',
            'reservation_ttl' => 15,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $armadio = Cabinet::withoutGlobalScopes()->where('code', 'GAL')->firstOrFail();

    expect($armadio->tariff_cents)->toBe(750);        // euro nel form, centesimi nel database
    expect($armadio->reservation_ttl)->toBe(900);     // minuti nel form, secondi nel database
});

it('⚠️ NON mostra il prezzo al gestore del locale', function () {
    comeGestore();

    // Il listino è una decisione della piattaforma: chi incassa non lo ritocca.
    livewire(CreateCabinet::class)->assertFormFieldIsHidden('tariff_cents');

    $armadio = Cabinet::factory()->for($this->locale)->create(['tariff_cents' => 500]);

    livewire(EditCabinet::class, ['record' => $armadio->getKey()])
        ->assertFormFieldIsHidden('tariff_cents');
});

it('⚠️⚠️ NON lascia che il gestore forzi il prezzo con una richiesta costruita a mano', function () {
    // ⚠️ È IL TEST CHE CONTA. Nascondere un campo non è impedire di scriverlo: Livewire riceve
    //    i dati dal browser, e un browser lo si può convincere a dire quello che si vuole.
    comeGestore();

    $armadio = Cabinet::factory()->for($this->locale)->create(['tariff_cents' => 500]);

    livewire(EditCabinet::class, ['record' => $armadio->getKey()])
        ->fillForm(['name' => 'Rinominato', 'tariff_cents' => '0.00'])   // gratis, per sé
        ->call('save')
        ->assertHasNoFormErrors();

    expect($armadio->refresh()->name)->toBe('Rinominato');   // il resto passa
    expect($armadio->tariff_cents)->toBe(500);               // il prezzo NO
});

it('lascia che sia la piattaforma a decidere il prezzo', function () {
    comePiattaforma();

    $armadio = Cabinet::factory()->for($this->locale)->create(['tariff_cents' => 500]);

    livewire(EditCabinet::class, ['record' => $armadio->getKey()])
        ->fillForm(['tariff_cents' => '9.90'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($armadio->refresh()->tariff_cents)->toBe(990);
});

it('la home è una pagina pubblica, e non chiede a nessuno di autenticarsi', function () {
    // ⚠️ Un dominio che risponde a chiunque passi con una schermata di login dice una cosa sola:
    //    "qui non c'è niente per te". Anche a chi stava valutando se comprarlo.
    $this->get('/')
        ->assertOk()
        ->assertSee('LockUpWorld')
        ->assertDontSee('Password');   // nessun form di login in faccia a un visitatore
});

it('⚠️ la home non dice NIENTE a un estraneo sul parco clienti', function () {
    /*
     * ⚠️ È il modo tipico in cui queste pagine perdono informazioni: si aggiunge "giusto il
     *    numero di locali attivi" per far vedere che il prodotto è vivo, e si è appena detto a
     *    un estraneo quanti clienti abbiamo — e, con qualche visita nel tempo, come stiamo
     *    andando.
     *
     * Il test è volutamente stupido: crea un locale con un nome inconfondibile e verifica che
     * quel nome NON compaia. Se un giorno qualcuno aggiungerà una vetrina "i nostri clienti",
     * questo test lo sveglierà.
     */
    Tenant::factory()->create(['name' => 'Teatro Segretissimo']);

    $risposta = $this->get('/');

    $risposta->assertOk()->assertDontSee('Teatro Segretissimo');
    expect($risposta->getContent())->not->toContain($this->locale->name);
});

it('offre a chi è già dentro il link al SUO pannello, non a quello che lo respinge', function () {
    // I due pannelli si respingono a vicenda: offrire il link sbagliato è offrire un rimbalzo.
    $this->actingAs($this->gestore)->get('/')->assertSee('href="/app"', escape: false);

    Auth::forgetGuards();

    $this->actingAs($this->piattaforma)->get('/')->assertSee('href="/admin"', escape: false);
});
