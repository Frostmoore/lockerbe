<?php

/*
 * SEI GIÀ DENTRO, MA SUL PANNELLO SBAGLIATO.
 *
 * ⚠️ I due pannelli si respingono a vicenda, e DEVE restare così: `/admin` gira in bypass e vede
 * gli armadi, gli utenti e il registro di TUTTI i clienti.
 *
 * Quello che cambia è **come lo si dice**: un utente già autenticato, che ha un pannello
 * legittimo, non deve sbattere contro un 403 — va portato sul suo. Il confine non si allenta di
 * un millimetro; è il muro che diventa una porta.
 *
 * ⚠️ Il test che conta davvero è l'ultimo: **chi non ha nessun pannello continua a prendere 403**.
 * Se lo reindirizzassimo "per gentilezza", finirebbe in un ciclo infinito — il modo classico di
 * trasformare un errore chiaro in una pagina che non carica mai.
 */

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->locale = Tenant::factory()->create();

    $this->gestore = User::factory()->forTenant($this->locale)->create();
    $this->gestore->assignRole('tenant_admin');

    $this->piattaforma = User::factory()->create(['tenant_id' => null]);
    $this->piattaforma->assignRole('platform_admin');
});

it('porta un utente di PIATTAFORMA da /app al SUO pannello, invece di dargli 403', function () {
    // È esattamente il caso reale: entri come smp-webmaster, digiti /app, e prendevi un muro.
    $this->actingAs($this->piattaforma)
        ->get('/app')
        ->assertRedirect('/admin');
});

it('porta un GESTORE da /admin al suo pannello, invece di dargli 403', function () {
    $this->actingAs($this->gestore)
        ->get('/admin')
        ->assertRedirect('/app');
});

it('non tocca chi è già sul pannello giusto', function () {
    $this->actingAs($this->gestore)->get('/app')->assertOk();

    Auth::forgetGuards();   // ⚠️ la guard tiene in cache l'utente fra due richieste dello stesso test

    $this->actingAs($this->piattaforma)->get('/admin')->assertOk();
});

it('manda al login chi non è entrato, non a un altro pannello', function () {
    $this->get('/app')->assertRedirect('/app/login');
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('⚠️ chi non ha NESSUN pannello prende ancora 403 — e non un ciclo di redirect', function () {
    // Un account sospeso non entra da nessuna parte. Reindirizzarlo "per gentilezza" verso
    // l'altro pannello, che lo respingerebbe a sua volta, produrrebbe un rimpallo infinito.
    $sospeso = User::factory()->forTenant($this->locale)->create(['status' => 'disabled']);
    $sospeso->assignRole('tenant_admin');

    $this->actingAs($sospeso)->get('/app')->assertForbidden();

    Auth::forgetGuards();

    $this->actingAs($sospeso)->get('/admin')->assertForbidden();
});

it('⚠️⚠️ il confine NON si è allentato: un gestore non VEDE /admin, ci passa solo attraverso', function () {
    /*
     * ⚠️ Il rischio di questa modifica era esattamente questo: trasformare un diniego in un
     *    reindirizzamento e, per sbaglio, far girare una richiesta DENTRO il pannello sbagliato
     *    prima di rimbalzarla. `/admin` gira in bypass: mezza pagina renderizzata lì sarebbe
     *    mezza pagina con gli armadi di tutti i clienti.
     *
     *    Quindi si verifica che la risposta sia SOLO un redirect: nessun contenuto del pannello
     *    di piattaforma deve essere finito nel corpo.
     */
    $risposta = $this->actingAs($this->gestore)->get('/admin');

    $risposta->assertRedirect('/app');
    expect($risposta->getContent())->not->toContain('Locali');   // la voce di menu di /admin
});
