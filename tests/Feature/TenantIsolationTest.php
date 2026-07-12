<?php

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Il test §17.1 del piano — il piu' importante del progetto.
 *
 * Non verifica il global scope di Eloquent: quello e' comodo ma aggirabile (una query
 * grezza, un DB::table(), una relazione caricata storta). Verifica la **Row Level
 * Security di Postgres**, cioe' la rete che regge quando il livello applicativo viene
 * scavalcato.
 *
 * Il modo in cui questi test sono scritti e' deliberato: usano `DB::select` con SQL
 * grezzo, che salta Eloquent per intero. Se passano lo stesso, l'isolamento e' nel
 * database — che e' l'unico posto dove serve davvero.
 */

it('non lascia vedere i tenant altrui, nemmeno con SQL grezzo', function () {
    /** @var TenantContext $context */
    $context = app(TenantContext::class);

    $a = Tenant::factory()->create(['name' => 'Locale A']);
    $b = Tenant::factory()->create(['name' => 'Locale B']);

    $context->setTenant($a->id);

    // SQL grezzo: nessun global scope, nessun WHERE tenant_id scritto da noi.
    $rows = DB::select('SELECT id, name FROM tenants');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($a->id)
        ->and($rows[0]->name)->toBe('Locale A');

    // Anche chiedendo esplicitamente l'altro, il database non lo restituisce.
    expect(DB::select('SELECT id FROM tenants WHERE id = ?', [$b->id]))->toBeEmpty();
});

it('non lascia vedere gli utenti di un altro tenant, nemmeno con SQL grezzo', function () {
    /** @var TenantContext $context */
    $context = app(TenantContext::class);

    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    User::factory()->forTenant($a)->create(['username' => 'staff-a']);
    User::factory()->forTenant($b)->create(['username' => 'staff-b']);

    $context->setTenant($a->id);

    $usernames = array_column(DB::select('SELECT username FROM users'), 'username');

    expect($usernames)->toContain('staff-a')
        ->and($usernames)->not->toContain('staff-b');
});

it('non lascia SCRIVERE nel tenant di un altro', function () {
    /** @var TenantContext $context */
    $context = app(TenantContext::class);

    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $context->setTenant($a->id);

    // La policy ha un WITH CHECK, non solo un USING: leggere i dati di B e' impedito,
    // ma lo e' anche piantarci dentro roba nostra. Senza questo, un tenant potrebbe
    // creare un utente (domani: un comando di apertura) dentro il locale di un altro.
    expect(fn () => DB::insert(
        'INSERT INTO sites (id, tenant_id, name, created_at, updated_at) VALUES (?, ?, ?, now(), now())',
        [(string) Str::uuid7(), $b->id, 'Sede intrusa'],
    ))->toThrow(QueryException::class);
});

it('non mostra NIENTE quando il contesto non e\' impostato (fail-closed)', function () {
    /** @var TenantContext $context */
    $context = app(TenantContext::class);

    Tenant::factory()->count(3)->create();

    // Contesto dimenticato: ne' tenant ne' bypass. La tentazione, scrivendo le policy,
    // sarebbe stata "se non c'e' contesto mostra tutto" — ed e' esattamente cosi' che
    // una svista diventa una fuga di dati silenziosa. Qui non si vede niente.
    $context->forget();

    expect(DB::select('SELECT id FROM tenants'))->toBeEmpty();
});

it('lascia vedere tutti i tenant al platform_admin', function () {
    /** @var TenantContext $context */
    $context = app(TenantContext::class);

    Tenant::factory()->count(3)->create();

    $context->bypass();

    expect(DB::select('SELECT id FROM tenants'))->toHaveCount(3);
});

it('applica il filtro anche a Eloquent, non solo al database', function () {
    /** @var TenantContext $context */
    $context = app(TenantContext::class);

    $a = Tenant::factory()->create();
    Tenant::factory()->create();

    $context->setTenant($a->id);

    expect(Tenant::query()->count())->toBe(1)
        ->and(Tenant::query()->first()?->id)->toBe($a->id);
});
