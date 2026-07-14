<?php

/*
 * IL PAGAMENTO COL QR — dal telefono del cliente.
 *
 * ⚠️ Questi test nascono da un 500 vero, e la causa era peggiore del sintomo.
 *
 * L'email col codice d'accesso veniva mandata **in modo sincrono, dentro la transazione** che
 * conferma il pagamento. Bastava che l'SMTP tossisse — un certificato autofirmato, il relay giù —
 * e l'eccezione faceva **rollback di tutto**: il cliente aveva pagato, e si ritrovava un 500,
 * nessuna sessione, e il vano bloccato su `reserved` finché non scadeva la prenotazione.
 *
 * **Un'email non ha nessun diritto di disfare un incasso.**
 */

use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Mail\CodiceAccesso;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\Locker;
use App\Models\Session;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config()->set('locker.mock_panel', true);

    $this->locale = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->locale->id);

    $this->armadio = Cabinet::factory()->for($this->locale)->create(['last_seen_at' => now()]);
    Locker::factory()->for($this->armadio)->create(['number' => 1, 'status' => 'free']);
    Device::factory()->for($this->locale)->create(['cabinet_id' => $this->armadio->id]);
});

/** Il cliente chiede un vano al chiosco e ottiene il token pubblico che finisce nel QR. */
function prenota(): string
{
    // ⚠️ `request()` restituisce il token **in chiaro una volta sola**: nel database c'è solo
    //    il suo SHA-256. È l'unica cosa che il cliente possiede.
    return app(SessionManager::class)->request(test()->armadio, null, 'qr')['token'];
}

it('paga col QR: il vano diventa occupato e il codice viene ACCODATO, non spedito in linea', function () {
    Mail::fake();

    $token = prenota();

    $this->post("/pay/{$token}", ['email' => 'cliente@esempio.it'])
        ->assertRedirect(route('pay.show', ['token' => $token]));

    $sessione = Session::withoutGlobalScopes()->latest('created_at')->firstOrFail();

    expect($sessione->status)->toBe('active');
    expect($sessione->locker()->first()->status)->toBe('occupied');

    // ⚠️ ACCODATA, non spedita: `assertQueued`, non `assertSent`. Se un giorno qualcuno
    //    tornasse a `Mail::send()`, questo test si accorge — ed è il test che protegge
    //    l'incasso dal server di posta.
    Mail::assertQueued(CodiceAccesso::class);
    Mail::assertNothingSent();
});

it('⚠️⚠️ se la posta è ROTTA, il pagamento resta valido lo stesso', function () {
    /*
     * ⚠️ È IL TEST CHE CONTA. Si rompe la posta di proposito e si verifica che il cliente NON
     *    perda quello che ha pagato.
     *
     *    Prima: eccezione → rollback → 500 → nessuna sessione → vano bloccato su `reserved`.
     *    Ora:   il guasto finisce nel registro, e il vano è suo.
     */
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP giù'));

    $token = prenota();

    $this->post("/pay/{$token}", ['email' => 'cliente@esempio.it'])
        ->assertRedirect(route('pay.show', ['token' => $token]));   // ← NON un 500

    $sessione = Session::withoutGlobalScopes()->latest('created_at')->firstOrFail();

    expect($sessione->status)->toBe('active');                        // ha pagato: il vano è suo
    expect($sessione->locker()->first()->status)->toBe('occupied');   // e NON è tornato reserved
});

it('annulla la prenotazione dal telefono, e il vano torna libero SUBITO', function () {
    // ⚠️ Senza, chi apriva il QR e ci ripensava lasciava il vano bloccato fino allo scadere
    //    della prenotazione: in una serata di punta, un armadietto vuoto che risulta occupato.
    $token = prenota();

    $vano = Locker::withoutGlobalScopes()->where('cabinet_id', $this->armadio->id)->sole();
    expect($vano->refresh()->status)->toBe('reserved');

    $this->post("/pay/{$token}/cancel")
        ->assertRedirect(route('pay.show', ['token' => $token]));

    expect($vano->refresh()->status)->toBe('free');
    expect($vano->current_session_id)->toBeNull();

    // ⚠️ E la pagina non deve piu' dire "Pagato ✅" — lo diceva, perche' il ramo `@else`
    //    catturava OGNI stato diverso da `created`. Un cliente che annulla e legge "pagato"
    //    torna a cercare un vano che non e' suo.
    $this->get("/pay/{$token}")
        ->assertOk()
        ->assertSee('Prenotazione annullata')
        ->assertDontSee('Pagato');
});

it('⚠️ NON lascia annullare una sessione GIA\' PAGATA: dentro c\'è la roba di qualcuno', function () {
    // ⚠️ §7.0 — un'azione ambigua non deve MAI liberare un vano. Chi ha pagato e vuole indietro
    //    le sue cose fa *checkout*, che apre lo sportello; "annulla" lo libererebbe **con dentro
    //    il cappotto**, e il vano verrebbe riassegnato a un altro cliente.
    Mail::fake();

    $token = prenota();

    $this->post("/pay/{$token}", ['email' => 'cliente@esempio.it']);

    $vano = Locker::withoutGlobalScopes()->where('cabinet_id', $this->armadio->id)->sole();
    expect($vano->refresh()->status)->toBe('occupied');

    // ⚠️ Il rifiuto e' giusto, ma non deve uscire come un 500: al cliente si mostra la
    //    realta' adesso — il vano e' suo, ha pagato. (Il bottone, del resto, sulla pagina
    //    non c'e' nemmeno: chi arriva qui ha una pagina vecchia in mano, o sta forzando.)
    $this->post("/pay/{$token}/cancel")
        ->assertRedirect(route('pay.show', ['token' => $token]));

    expect($vano->refresh()->status)->toBe('occupied');   // ⚠️ il vano resta suo

    $this->get("/pay/{$token}")->assertSee('Pagato');
});
