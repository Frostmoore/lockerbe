<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Il registro che serve il giorno che sparisce un cappotto (piano §14, §17.7).
 *
 * Due proprieta', e nessuna delle due e' negoziabile:
 *   - la catena di hash rileva ogni manomissione;
 *   - il database VIETA update e delete, quindi la manomissione non passa nemmeno.
 */

it('incatena i record con gli hash', function () {
    /** @var AuditLogger $audit */
    $audit = app(AuditLogger::class);

    $audit->log('locker.open');
    $audit->log('session.checkout');
    $audit->log('auth.login');

    $rows = DB::select('SELECT id, prev_hash, hash FROM audit_logs ORDER BY id');

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->prev_hash)->toBeNull()
        ->and($rows[1]->prev_hash)->toBe($rows[0]->hash)
        ->and($rows[2]->prev_hash)->toBe($rows[1]->hash);
});

it('conferma la catena integra con audit:verify-chain', function () {
    /** @var AuditLogger $audit */
    $audit = app(AuditLogger::class);

    foreach (['locker.open', 'locker.open', 'session.checkout'] as $action) {
        $audit->log($action);
    }

    // Se questo comando fallisse su una catena appena scritta, vorrebbe dire che chi
    // scrive e chi verifica calcolano l'hash in modo diverso: l'allarme suonerebbe
    // sempre, e nel giro di una settimana qualcuno lo spegnerebbe.
    $this->artisan('audit:verify-chain')
        ->expectsOutputToContain('Catena integra: 3 record')
        ->assertSuccessful();
});

it('rifiuta UPDATE e DELETE sull\'audit: e\' il database a dirlo, non noi', function () {
    /** @var AuditLogger $audit */
    $audit = app(AuditLogger::class);

    $audit->log('locker.open');

    // Append-only imposto da Postgres (REVOKE UPDATE, DELETE su locker_app). Nemmeno un
    // bug nostro, o un dipendente con l'accesso all'applicazione, puo' riscrivere la
    // storia: servirebbero le credenziali di amministratore del database.
    expect(fn () => DB::update("UPDATE audit_logs SET action = 'niente'"))
        ->toThrow(QueryException::class);

    expect(fn () => DB::delete('DELETE FROM audit_logs'))
        ->toThrow(QueryException::class);
});

it('registra il tenant dell\'azione, e non lo mostra agli altri tenant', function () {
    /** @var TenantContext $context */
    $context = app(TenantContext::class);
    /** @var AuditLogger $audit */
    $audit = app(AuditLogger::class);

    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $context->setTenant($a->id);
    $audit->log('locker.open');

    $context->setTenant($b->id);
    $audit->log('locker.open');

    // Ogni tenant vede solo la propria storia: l'audit e' tenant-scoped come tutto il
    // resto (RLS), pur essendo scritto su una catena globale.
    $context->setTenant($a->id);
    expect(DB::select('SELECT tenant_id FROM audit_logs'))->toHaveCount(1);

    $context->bypass();
    expect(DB::select('SELECT tenant_id FROM audit_logs'))->toHaveCount(2);
});
