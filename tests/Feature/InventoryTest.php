<?php

use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Locker;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->forTenant($this->tenant)->create();
    $this->admin->assignRole('tenant_admin');

    $this->staff = User::factory()->forTenant($this->tenant)->create();
    $this->staff->assignRole('tenant_staff');
});

/*
 * F2 — Inventario: armadi, dispositivi, vani.
 */

it('crea un armadio e genera i vani con la mappa RS-485', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/cabinets', [
            'name' => 'Guardaroba Ingresso',
            'code' => 'G1',
            'lockers' => 20,
            'settings' => ['channels_per_board' => 8],
        ])
        ->assertCreated();

    $cabinet = Cabinet::query()->findOrFail($response->json('data.id'));

    expect($cabinet->lockers()->count())->toBe(20);

    // Con 8 canali per scheda: i vani 1-8 sulla scheda 1, i 9-16 sulla 2, i 17-20 sulla 3.
    $first = $cabinet->lockers()->where('number', 1)->firstOrFail();
    $ninth = $cabinet->lockers()->where('number', 9)->firstOrFail();
    $last = $cabinet->lockers()->where('number', 20)->firstOrFail();

    expect([$first->board_address, $first->channel])->toBe([1, 1])
        ->and([$ninth->board_address, $ninth->channel])->toBe([2, 1])
        ->and([$last->board_address, $last->channel])->toBe([3, 4]);
});

it('rifiuta due vani sullo stesso canale RS-485', function () {
    $cabinet = Cabinet::factory()->forTenant($this->tenant)->create();

    Locker::factory()->forCabinet($cabinet)->create([
        'number' => 1, 'board_address' => 1, 'channel' => 1,
    ]);

    // ⚠️ Due vani sullo stesso (scheda, canale) significherebbe che aprendo il vano 1 si
    // apre anche il 2: l'armadietto di qualcun altro. Il database non deve permetterlo.
    expect(fn () => Locker::factory()->forCabinet($cabinet)->create([
        'number' => 2, 'board_address' => 1, 'channel' => 1,
    ]))->toThrow(QueryException::class);
});

it('rifiuta due vani con lo stesso numero nello stesso armadio', function () {
    $cabinet = Cabinet::factory()->forTenant($this->tenant)->create();

    Locker::factory()->forCabinet($cabinet)->create(['number' => 7, 'board_address' => 1, 'channel' => 7]);

    expect(fn () => Locker::factory()->forCabinet($cabinet)->create([
        'number' => 7, 'board_address' => 2, 'channel' => 3,
    ]))->toThrow(QueryException::class);
});

it('permette lo stesso codice armadio a due locali diversi', function () {
    $altro = Tenant::factory()->create();

    Cabinet::factory()->forTenant($this->tenant)->create(['code' => 'A1']);

    // `code` e' unico PER TENANT: due locali possono avere entrambi l'armadio "A1".
    expect(fn () => Cabinet::factory()->forTenant($altro)->create(['code' => 'A1']))
        ->not->toThrow(QueryException::class);
});

it('non mostra gli armadi di un altro locale (404, non 403)', function () {
    $altro = Tenant::factory()->create();
    $cabinetAltrui = Cabinet::factory()->forTenant($altro)->create();

    // 404 e non 403: non si conferma nemmeno che quell'armadio esista da qualche parte.
    $this->actingAs($this->admin)
        ->getJson("/api/v1/cabinets/{$cabinetAltrui->id}")
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->getJson('/api/v1/cabinets')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('nega a tenant_staff la creazione di armadi', function () {
    $this->actingAs($this->staff)
        ->postJson('/api/v1/cabinets', ['name' => 'X', 'code' => 'X1', 'lockers' => 4])
        ->assertForbidden();
});

it('mette un vano fuori servizio e lo esclude dagli assegnabili', function () {
    $cabinet = Cabinet::factory()->forTenant($this->tenant)->create();
    $locker = Locker::factory()->forCabinet($cabinet)->create(['number' => 3, 'board_address' => 1, 'channel' => 3]);

    $this->actingAs($this->staff)
        ->patchJson("/api/v1/lockers/{$locker->id}", ['status' => 'out_of_service'])
        ->assertOk()
        ->assertJsonPath('data.status', 'out_of_service')
        ->assertJsonPath('data.assignable', false);

    // ⚠️ Un vano rotto non deve finire a un cliente che poi non riesce a riaprirlo.
    expect(Locker::query()->freeInCabinet($cabinet->id)->count())->toBe(0);
});

it('non mette fuori servizio un vano occupato', function () {
    $cabinet = Cabinet::factory()->forTenant($this->tenant)->create();
    $locker = Locker::factory()->forCabinet($cabinet)->occupied()->create([
        'number' => 5, 'board_address' => 1, 'channel' => 5,
    ]);

    // Dentro c'e' la roba di qualcuno: prima il checkout, poi lo si toglie dal servizio.
    $this->actingAs($this->staff)
        ->patchJson("/api/v1/lockers/{$locker->id}", ['status' => 'out_of_service'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'locker_busy');
});

it('considera offline un armadio con heartbeat scaduto', function () {
    $fresco = Cabinet::factory()->forTenant($this->tenant)->online()->create();
    $vecchio = Cabinet::factory()->forTenant($this->tenant)->stale()->create();
    $manutenzione = Cabinet::factory()->forTenant($this->tenant)->maintenance()->create();

    expect($fresco->isOnline())->toBeTrue()
        // Heartbeat di 3 ore fa: l'armadio e' irraggiungibile, per quanto la colonna
        // `status` dica ancora 'online'. Da F4, un `open` verso di lui deve dare 409.
        ->and($vecchio->isOnline())->toBeFalse()
        // Un tecnico ci sta lavorando: non gli si aprono i vani in faccia.
        ->and($manutenzione->isOnline())->toBeFalse();
});

it('marca offline gli armadi silenziosi con cabinets:mark-offline', function () {
    $vecchio = Cabinet::factory()->forTenant($this->tenant)->stale()->create();
    $fresco = Cabinet::factory()->forTenant($this->tenant)->online()->create();

    $this->artisan('cabinets:mark-offline')->assertSuccessful();

    expect($vecchio->fresh()->status)->toBe('offline')
        ->and($fresco->fresh()->status)->toBe('online');
});

it('non lascia vedere i vani di un altro locale, nemmeno con SQL grezzo', function () {
    $altro = Tenant::factory()->create();
    $cabinetAltrui = Cabinet::factory()->forTenant($altro)->create();
    Locker::factory()->forCabinet($cabinetAltrui)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    $mio = Cabinet::factory()->forTenant($this->tenant)->create();
    Locker::factory()->forCabinet($mio)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    // La regola che F1 ha lasciato in eredita': Rls::enable() su ogni tabella nuova.
    // Se qualcuno se ne dimenticasse per `lockers`, questo test diventerebbe rosso — ed e'
    // il motivo per cui esiste.
    app(TenantContext::class)->setTenant($this->tenant->id);

    expect(DB::select('SELECT id FROM lockers'))->toHaveCount(1);
    expect(DB::select('SELECT id FROM cabinets'))->toHaveCount(1);
});
