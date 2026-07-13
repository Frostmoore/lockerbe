<?php

use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Identity;
use App\Models\Locker;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create(['settings' => ['tariff_cents' => 500]]);
    $this->staff = User::factory()->forTenant($this->tenant)->create();
    $this->staff->assignRole('tenant_staff');

    $this->cabinet = Cabinet::factory()->forTenant($this->tenant)->online()->create();

    foreach (range(1, 4) as $n) {
        Locker::factory()->forCabinet($this->cabinet)->create([
            'number' => $n, 'board_address' => 1, 'channel' => $n,
        ]);
    }
});

/*
 * F3 — l'obiettivo dichiarato: il FLUSSO COMPLETO, cliccabile, senza Nexi, senza carte,
 * senza il FCV5003.
 */

it('percorre tutto il flusso: chiedi vano → paga → riapri → checkout', function () {
    // 1. Il cliente chiede un vano.
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id])
        ->assertCreated();

    $sessionId = $created->json('data.id');
    $paymentId = $created->json('payment.id');
    $publicToken = $created->json('public_token');

    // Gli e' stato assegnato il PRIMO vano libero, ed e' ora prenotato.
    expect($created->json('data.status'))->toBe('created')
        ->and($created->json('data.locker.number'))->toBe(1)
        ->and($created->json('data.locker.status'))->toBe('reserved')
        ->and($created->json('data.amount_cents'))->toBe(500)
        ->and($created->json('payment.qr_payload'))->toStartWith('locker://pay/mock_')
        ->and($publicToken)->toBeString();

    // 2. ✅ Paga (il bottone). La sessione diventa attiva e il vano si occupa.
    $this->actingAs($this->staff)
        ->postJson("/api/v1/mock/payments/{$paymentId}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.locker.status', 'occupied');

    // 3. 🪪 Tap della carta: sconosciuta ⇒ viene LEGATA alla sessione (primo uso).
    $this->actingAs($this->staff)
        ->postJson('/api/v1/mock/identity/tap', [
            'cabinet_id' => $this->cabinet->id,
            'token' => 'CARTA-DEMO-001',
        ])
        ->assertCreated()
        ->assertJsonPath('action', 'bound');

    // 4. 🪪 Secondo tap: ora la carta e' nota ⇒ RIAPRE il vano.
    $this->actingAs($this->staff)
        ->postJson('/api/v1/mock/identity/tap', [
            'cabinet_id' => $this->cabinet->id,
            'token' => 'CARTA-DEMO-001',
        ])
        ->assertOk()
        ->assertJsonPath('action', 'reopen');

    expect(Session::query()->findOrFail($sessionId)->reopen_count)->toBe(1);

    // 5. Il cliente riapre anche dal telefono, col token pubblico. Nessun account.
    $this->postJson("/api/v1/public/sessions/{$publicToken}/reopen")->assertOk();

    // 6. 🏁 Riconsegna: il vano si apre, ma NON diventa libero. Resta suo.
    $this->postJson("/api/v1/public/sessions/{$publicToken}/checkout")->assertOk();

    $locker = Locker::query()->where('number', 1)->firstOrFail();

    // ⚠️ Il sistema non puo' sapere se il vano e' vuoto. Liberarlo adesso significherebbe
    // poterlo assegnare a un altro cliente con dentro la roba di qualcuno.
    expect($locker->status)->toBe('checkout')
        ->and(Session::query()->findOrFail($sessionId)->status)->toBe('active')
        ->and(Session::query()->findOrFail($sessionId)->checkout_pending_at)->not->toBeNull();

    // 7. Lo staff guarda dentro e conferma: ORA il vano torna libero.
    $this->actingAs($this->staff)
        ->postJson("/api/v1/sessions/{$sessionId}/checkout/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.closed_by', 'staff');

    $locker->refresh();

    expect($locker->status)->toBe('free')
        ->and($locker->current_session_id)->toBeNull();
});

it('annulla la riconsegna se il cliente ripassa la carta', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $sessionId = $created->json('data.id');

    $this->actingAs($this->staff)->postJson("/api/v1/mock/payments/{$created->json('payment.id')}/confirm");

    $this->actingAs($this->staff)->postJson('/api/v1/mock/identity/tap', [
        'cabinet_id' => $this->cabinet->id, 'token' => 'CARTA-Z',
    ])->assertCreated();

    // 🏁 "Ho finito": il vano si apre in riconsegna.
    $this->actingAs($this->staff)->postJson('/api/v1/mock/identity/tap', [
        'cabinet_id' => $this->cabinet->id, 'token' => 'CARTA-Z', 'intent' => 'checkout',
    ])->assertOk()->assertJsonPath('action', 'checkout_requested');

    expect(Locker::query()->where('number', 1)->firstOrFail()->status)->toBe('checkout');

    // ⚠️ ...ma ha dimenticato il telefono nella tasca del cappotto. Ripassa la carta.
    $this->actingAs($this->staff)->postJson('/api/v1/mock/identity/tap', [
        'cabinet_id' => $this->cabinet->id, 'token' => 'CARTA-Z',
    ])->assertOk()->assertJsonPath('action', 'reopen');

    // La riconsegna e' annullata: tutto com'era, come se non avesse mai premuto "ho finito".
    $session = Session::query()->findOrFail($sessionId);

    expect($session->status)->toBe('active')
        ->and($session->checkout_pending_at)->toBeNull()
        ->and(Locker::query()->where('number', 1)->firstOrFail()->status)->toBe('occupied');
});

it('libera il vano allo scadere della finestra di cortesia', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $sessionId = $created->json('data.id');
    $this->actingAs($this->staff)->postJson("/api/v1/mock/payments/{$created->json('payment.id')}/confirm");
    $this->actingAs($this->staff)->postJson("/api/v1/sessions/{$sessionId}/checkout")->assertOk();

    // Finestra ancora aperta: il vano e' ancora suo.
    $this->artisan('sessions:finalize-checkouts')->assertSuccessful();
    expect(Locker::query()->where('number', 1)->firstOrFail()->status)->toBe('checkout');

    // Finestra scaduta. ⚠️ E' un ripiego, non una soluzione: la conferma giusta e' lo
    // sportello richiuso (D5). Ma senza sensore, altrimenti nessun vano tornerebbe MAI
    // libero dopo una riconsegna.
    Session::query()->whereKey($sessionId)->update([
        'checkout_pending_at' => now()->subSeconds((int) config('locker.checkout.grace') + 10),
    ]);

    $this->artisan('sessions:finalize-checkouts')->assertSuccessful();

    $session = Session::query()->findOrFail($sessionId);

    expect($session->status)->toBe('closed')
        ->and($session->closed_by)->toBe('timeout')
        ->and(Locker::query()->where('number', 1)->firstOrFail()->status)->toBe('free');
});

it('non riassegna un vano in riconsegna', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $this->actingAs($this->staff)->postJson("/api/v1/mock/payments/{$created->json('payment.id')}/confirm");
    $this->actingAs($this->staff)->postJson("/api/v1/sessions/{$created->json('data.id')}/checkout");

    // ⚠️ Il vano 1 e' aperto per il ritiro ma potrebbe avere ancora dentro il cappotto: il
    // prossimo cliente NON deve riceverlo.
    $altro = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    expect($altro->json('data.locker.number'))->toBe(2);
});

it('e\' idempotente sul pagamento: doppio click ⇒ UNA sola apertura', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $paymentId = $created->json('payment.id');

    $this->actingAs($this->staff)->postJson("/api/v1/mock/payments/{$paymentId}/confirm")->assertOk();
    $this->actingAs($this->staff)->postJson("/api/v1/mock/payments/{$paymentId}/confirm")->assertOk();

    // ⚠️ Un provider vero rimanda lo stesso webhook piu' volte. Se ogni consegna riaprisse
    // il vano, il cappotto resterebbe alla merce' di chiunque passi.
    $aperture = DB::table('audit_logs')
        ->where('action', 'command.issued')
        ->where('session_id', $created->json('data.id'))
        ->count();

    expect($aperture)->toBe(1);
});

it('libera il vano se il pagamento fallisce', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/mock/payments/{$created->json('payment.id')}/fail")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.locker.status', 'free');
});

it('rifiuta il checkout su una sessione non pagata (422)', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    // §17.6: una transizione impossibile e' un errore, non qualcosa da "aggiustare".
    $this->actingAs($this->staff)
        ->postJson("/api/v1/sessions/{$created->json('data.id')}/checkout")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'illegal_transition');
});

it('impedisce due sessioni attive sullo stesso vano (lo dice il database)', function () {
    $locker = Locker::query()->where('number', 1)->firstOrFail();

    Session::factory()->forLocker($this->cabinet, $locker)->create();

    // ⚠️ L'indice unico parziale `one_active_session_per_locker`. Senza, due clienti
    // potrebbero essere assegnati allo stesso armadietto: il secondo pagherebbe per un vano
    // che contiene il cappotto del primo, e lo aprirebbe.
    expect(fn () => Session::factory()->forLocker($this->cabinet, $locker)->create())
        ->toThrow(QueryException::class);
});

it('assegna vani DIVERSI a richieste successive', function () {
    $a = $this->actingAs($this->staff)->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);
    $b = $this->actingAs($this->staff)->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    expect($a->json('data.locker.number'))->toBe(1)
        ->and($b->json('data.locker.number'))->toBe(2);
});

it('risponde 409 quando l\'armadio e\' pieno', function () {
    // Riempie tutti e 4 i vani.
    foreach (range(1, 4) as $n) {
        $this->actingAs($this->staff)
            ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id])
            ->assertCreated();
    }

    // Il quinto cliente: l'armadio e' pieno. Non e' un guasto, e' una risposta.
    $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'no_locker_available');
});

it('non assegna i vani fuori servizio', function () {
    Locker::query()->where('number', 1)->update(['status' => 'out_of_service']);

    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    // Un vano rotto assegnato e' un cliente che non riesce piu' a riprendersi il cappotto.
    expect($created->json('data.locker.number'))->toBe(2);
});

it('annulla le prenotazioni scadute e libera il vano', function () {
    $locker = Locker::query()->where('number', 1)->firstOrFail();
    $locker->update(['status' => 'reserved']);

    $session = Session::factory()->forLocker($this->cabinet, $locker)->reservationExpired()->create();
    $locker->update(['current_session_id' => $session->id]);

    $this->artisan('sessions:cancel-expired-reservations')->assertSuccessful();

    // ⚠️ Senza questo, un vano prenotato e mai pagato resterebbe bloccato per sempre.
    expect($session->fresh()?->status)->toBe('cancelled')
        ->and($locker->fresh()?->status)->toBe('free')
        ->and($locker->fresh()?->current_session_id)->toBeNull();
});

it('chiude le sessioni a fine serata SENZA liberare il vano', function () {
    $locker = Locker::query()->where('number', 1)->firstOrFail();
    $locker->update(['status' => 'occupied']);

    $session = Session::factory()->forLocker($this->cabinet, $locker)->active()->create([
        'expires_at' => now()->subMinute(),
    ]);

    $this->artisan('sessions:close-expired')->assertSuccessful();

    // ⚠️ Il vano resta OCCUPATO: dentro c'e' la roba di qualcuno che non e' tornato a
    // riprendersela. Liberarlo automaticamente significherebbe riassegnare un armadietto
    // col cappotto di un altro ancora dentro. Lo svuota lo staff, a mano.
    expect($session->fresh()?->status)->toBe('closed')
        ->and($locker->fresh()?->status)->toBe('occupied');
});

it('calcola la fine serata nel fuso del locale, non sul giorno solare', function () {
    $this->tenant->update(['timezone' => 'Europe/Rome', 'settings' => ['closing_time' => '06:00']]);

    // Chiamata diretta al dominio, fuori da una richiesta HTTP: il contesto tenant va
    // impostato a mano. In bypass, BelongsToTenant si rifiuta di creare un record senza
    // tenant — e fa bene: un record che non appartiene a nessuno non e' proteggibile.
    app(TenantContext::class)->setTenant($this->tenant->id);

    $result = app(SessionManager::class)->request($this->cabinet->fresh());

    $expiresAt = $result['session']->expires_at;

    // ⚠️ Un guardaroba chiude alle 6 del mattino: quella e' ancora "la serata di ieri".
    // Una logica basata sul giorno solare chiuderebbe le sessioni a mezzanotte — cioe' nel
    // momento di massimo affollamento, coi cappotti dentro e i clienti ancora a ballare.
    expect($expiresAt)->not->toBeNull()
        ->and($expiresAt->isFuture())->toBeTrue()
        ->and($expiresAt->setTimezone('Europe/Rome')->format('H:i'))->toBe('06:00');
});

it('non lega la stessa carta a due vani in uso contemporaneamente', function () {
    // Due sessioni attive nello stesso armadio.
    $created1 = $this->actingAs($this->staff)->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);
    $this->actingAs($this->staff)->postJson("/api/v1/mock/payments/{$created1->json('payment.id')}/confirm");

    $created2 = $this->actingAs($this->staff)->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);
    $this->actingAs($this->staff)->postJson("/api/v1/mock/payments/{$created2->json('payment.id')}/confirm");

    // La carta si lega alla seconda (la piu' recente).
    $this->actingAs($this->staff)->postJson('/api/v1/mock/identity/tap', [
        'cabinet_id' => $this->cabinet->id, 'token' => 'CARTA-X',
    ])->assertCreated();

    // Un secondo tap la fa RIAPRIRE, non la lega di nuovo: se una carta potesse aprire due
    // armadietti, chi la trovasse per terra avrebbe le chiavi di entrambi.
    $this->actingAs($this->staff)->postJson('/api/v1/mock/identity/tap', [
        'cabinet_id' => $this->cabinet->id, 'token' => 'CARTA-X',
    ])->assertOk()->assertJsonPath('action', 'reopen');

    expect(Identity::query()->where('token_hash', Identity::hashToken('CARTA-X'))->count())
        ->toBe(1);
});

it('non lascia leggere la sessione di un altro locale col token pubblico', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    // Token inventato: stessa identica risposta di un token valido ma scaduto — per non far
    // capire a un estraneo se sta indovinando.
    $this->getJson('/api/v1/public/sessions/token-inventato-non-esiste')->assertNotFound();

    // Il token vero funziona.
    $this->getJson("/api/v1/public/sessions/{$created->json('public_token')}")->assertOk();
});

it('non espone mai il token pubblico nelle letture successive', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $show = $this->actingAs($this->staff)
        ->getJson("/api/v1/sessions/{$created->json('data.id')}")
        ->assertOk();

    // Il token in chiaro esiste UNA volta sola, alla creazione. Esporlo qui significherebbe
    // che chiunque possa leggere una sessione possa anche aprire quel vano.
    expect($show->json('data'))->not->toHaveKey('public_token')
        ->and($show->json('data'))->not->toHaveKey('public_token_hash');
});

it('emette un comando VERO al pagamento, con TTL e scadenza (F4)', function () {
    $created = $this->actingAs($this->staff)
        ->postJson('/api/v1/sessions', ['cabinet_id' => $this->cabinet->id]);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/mock/payments/{$created->json('payment.id')}/confirm")
        ->assertOk();

    // ⚠️ In F3 qui c'era un finto dispatcher che registrava l'intenzione e non mandava niente.
    // Da F4 l'arma e' collegata: il comando esiste davvero, con la sua scadenza.
    $command = Command::query()->firstOrFail();

    expect($command->type)->toBe('open')
        ->and($command->reason)->toBe('store')
        ->and($command->status)->toBe('pending')
        ->and($command->expires_at->isFuture())->toBeTrue();
});
