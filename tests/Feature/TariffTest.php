<?php

use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\Locker;
use App\Models\Session;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * IL PREZZO DI UN VANO — **a cascata**: armadio → locale → default di piattaforma.
 *
 * ⚠️ Sempre in **centesimi**. I float non tengono i soldi (`0.1 + 0.2 !== 0.3`), e un
 * guardaroba che sbaglia un centesimo mille volte a sera lo sbaglia in bilancio.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create(['settings' => ['tariff_cents' => 800]]);
});

/** Un vano preso su questo armadio: quanto costa? */
function prezzoDi(Cabinet $cabinet): int
{
    Device::factory()->forCabinet($cabinet)->create();
    Locker::factory()->forCabinet($cabinet)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    /** @var Session $sessione */
    $sessione = app(TenantContext::class)->runForTenant(
        $cabinet->tenant_id,
        fn (): Session => app(SessionManager::class)->request($cabinet)['session'],
    );

    return $sessione->amount_cents;
}

it('usa il prezzo DELL\'ARMADIO quando ce l\'ha', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['tariff_cents' => 1200]);

    // ⚠️ Il prezzo è una proprietà del *posto in cui metti la roba*, non dell'azienda: l'armadio
    // all'ingresso e quello in fondo al corridoio possono costare diverso.
    expect(prezzoDi($armadio))->toBe(1200);
});

it('eredita il prezzo DEL LOCALE quando l\'armadio non ne ha uno', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['tariff_cents' => null]);

    expect(prezzoDi($armadio))->toBe(800);
});

it('⚠️ e il null NON è una copia: ritoccare il listino aggiorna gli armadi che lo seguono', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['tariff_cents' => null]);

    // Il gestore ritocca il listino del locale.
    $this->tenant->forceFill(['settings' => ['tariff_cents' => 1000]])->save();

    /*
     * ⚠️ È il motivo per cui l'armadio conserva un `null` invece di una copia del prezzo del
     * locale. Copiarlo "per comodità" al momento della creazione lo congelerebbe al prezzo di
     * quel giorno: il gestore ritocca il listino, gli armadi che non ha toccato restano al
     * prezzo vecchio, e nessuno se ne accorge finché non arriva un cliente a lamentarsi.
     */
    expect(prezzoDi($armadio->refresh()))->toBe(1000);
});

it('ripiega sul default di piattaforma se nessuno ha deciso niente', function () {
    $senzaListino = Tenant::factory()->create(['settings' => []]);
    $armadio = Cabinet::factory()->forTenant($senzaListino)->online()->create(['tariff_cents' => null]);

    // 5,00 €. Un default esiste perché un vano senza prezzo è un vano che si regala.
    expect(prezzoDi($armadio))->toBe(500);
});

it('lascia mettere un vano GRATIS, e non lo confonde con "non deciso"', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['tariff_cents' => 0]);

    // ⚠️ Zero e null sono due cose diverse: zero è "gratis" (una serata promozionale, il
    // guardaroba del personale), null è "non ho deciso, segui il locale". Un `?:` al posto di un
    // `!== null` li appiattirebbe, e il vano gratis costerebbe 8 euro.
    expect(prezzoDi($armadio))->toBe(0);
});

/*
 * ═══ QUANTO DURA LA PRENOTAZIONE ═══
 */

it('usa la durata di prenotazione DELL\'ARMADIO quando ce l\'ha', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create([
        'reservation_ttl' => 1800,   // mezz'ora: un teatro
    ]);

    Device::factory()->forCabinet($armadio)->create();
    Locker::factory()->forCabinet($armadio)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    /** @var Session $sessione */
    $sessione = app(TenantContext::class)->runForTenant(
        $this->tenant->id,
        fn (): Session => app(SessionManager::class)->request($armadio)['session'],
    );

    /*
     * ⚠️ È una decisione COMMERCIALE, non tecnica: un locale di passaggio la vuole corta, un
     * teatro lunga — una prenotazione che scade mentre il cliente cerca gli occhiali è un
     * cliente arrabbiato.
     *
     * ⚠️ `now()->diffInSeconds($futuro)`, non il contrario: Carbon restituisce una differenza
     * **con segno**, e scritta al rovescio dà un numero negativo. Un'asserzione tipo
     * `< 130` su un `-1800` passa — e passa per SEMPRE, qualunque cosa faccia il codice.
     */
    expect(now()->diffInSeconds($sessione->reserved_until))->toBeGreaterThan(1700);
});

it('eredita la durata DAL LOCALE quando l\'armadio non ne ha una', function () {
    $this->tenant->forceFill(['settings' => ['reservation_ttl' => 120]])->save();

    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['reservation_ttl' => null]);

    Device::factory()->forCabinet($armadio)->create();
    Locker::factory()->forCabinet($armadio)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    /** @var Session $sessione */
    $sessione = app(TenantContext::class)->runForTenant(
        $this->tenant->id,
        fn (): Session => app(SessionManager::class)->request($armadio->refresh())['session'],
    );

    // Vedi sopra: il verso della sottrazione conta.
    expect(now()->diffInSeconds($sessione->reserved_until))
        ->toBeGreaterThan(100)
        ->toBeLessThan(130);
});

/*
 * ═══ ANNULLA: il cliente ha cambiato idea ═══
 */

it('⚠️ libera il vano SUBITO quando il cliente annulla', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create();
    $chiosco = Device::factory()->forCabinet($armadio)->create();
    Locker::factory()->forCabinet($armadio)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    /** @var Session $sessione */
    $sessione = app(TenantContext::class)->runForTenant(
        $this->tenant->id,
        fn (): Session => app(SessionManager::class)->request($armadio)['session'],
    );

    expect($sessione->locker()->firstOrFail()->status)->toBe('reserved');

    $token = $chiosco->createToken('kiosk')->plainTextToken;

    /*
     * ⚠️ NON È CORTESIA: È INVENTARIO.
     *
     * Senza questo bottone, chi si ferma davanti alla schermata di pagamento e ci ripensa lascia
     * il vano bloccato per tutta la durata della prenotazione. In una serata di punta bastano
     * pochi ripensamenti per far risultare pieno un armadio mezzo vuoto — e il cliente dopo se
     * ne va senza che nessuno sappia perché.
     */
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/kiosk/sessions/{$sessione->id}/cancel")
        ->assertOk();

    $sessione->refresh();

    expect($sessione->status)->toBe('cancelled')
        ->and($sessione->locker()->firstOrFail()->status)->toBe('free')
        ->and($sessione->locker()->firstOrFail()->current_session_id)->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'session.cancelled']);
});

it('⚠️ non lascia annullare una sessione GIA\' PAGATA', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create();
    $chiosco = Device::factory()->forCabinet($armadio)->create();
    Locker::factory()->forCabinet($armadio)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    $sessione = app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($armadio): Session {
        ['session' => $s] = app(SessionManager::class)->request($armadio, null, 'nfc');
        $p = $s->payment()->firstOrFail();
        $p->forceFill(['payload' => ['card_token' => 'X']])->save();

        return app(SessionManager::class)->confirmPayment($p);
    });

    $token = $chiosco->createToken('kiosk')->plainTextToken;

    // ⚠️ Se i soldi sono stati presi, questo non è un annullamento: è un rimborso, ed è un'altra
    // cosa. Lasciarlo passare libererebbe un vano con dentro la roba di un cliente che ha pagato.
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/kiosk/sessions/{$sessione->id}/cancel")
        ->assertStatus(422);

    expect($sessione->refresh()->status)->toBe('active')
        ->and($sessione->locker()->firstOrFail()->status)->toBe('occupied');
});
