<?php

/*
 * LA PORTA DELL'EMULATORE.
 *
 * ⚠️ Questi test esistono per un buco vero, trovato aperto su un dominio pubblico.
 *
 * `MockPanel` prometteva "l'emulatore non esiste in produzione", ma lo prometteva guardando
 * `APP_ENV` — e il server vero gira `APP_ENV=staging` col flag acceso. La pagina era
 * raggiungibile da chiunque, elencava gli armadi di **tutti** i clienti e stampava nell'HTML le
 * credenziali MQTT del chiosco (ruotandole a ogni caricamento, cioe' buttando offline il chiosco
 * vero). Da li' si sottoscrive il topic dei comandi di un armadio e lo si apre.
 *
 * Il test che conta e' il primo: **senza password non si entra**.
 */

use App\Http\Middleware\ProtectEmulator;
use App\Models\Cabinet;
use App\Models\Tenant;
use App\Support\Bcrypt;

/* La password di prova, e il suo hash col prefisso "sbagliato": $2a$, quello che emettono i
 * generatori esterni. Se `Bcrypt` non lo normalizzasse, `Hash::check()` non risponderebbe
 * `false` — **esploderebbe** (trappola 30). */
const PASSWORD = 'la-parola-magica';

beforeEach(function () {
    config()->set('locker.mock_panel', true);
    config()->set('locker.emulator.password_hash', '$2a$'.substr(bcrypt(PASSWORD), 4));
});

it('NON lascia entrare senza password', function () {
    $this->get('/emulator')
        ->assertUnauthorized()
        ->assertSee('Password');
});

it('NON lascia entrare nel singolo emulatore senza password', function () {
    // ⚠️ Il vero bersaglio: e' QUESTA pagina che contiene le credenziali del chiosco.
    $armadio = Cabinet::factory()->for(Tenant::factory())->create();

    $this->get("/emulator/{$armadio->id}")->assertUnauthorized();
});

it('lascia entrare con la password giusta', function () {
    $this->post('/emulator/unlock', ['password' => PASSWORD])
        ->assertRedirect('/emulator');

    $this->assertTrue(session(ProtectEmulator::SESSIONE));

    $this->get('/emulator')->assertOk();
});

it('respinge la password sbagliata', function () {
    $this->post('/emulator/unlock', ['password' => 'quasi-la-parola-magica'])
        ->assertUnauthorized();

    expect(session(ProtectEmulator::SESSIONE))->toBeNull();

    $this->get('/emulator')->assertUnauthorized();
});

it('resta CHIUSO se la password non e\' configurata', function () {
    // ⚠️ Fail-closed. Una configurazione dimenticata non deve mai voler dire "entra pure":
    //    sarebbe esattamente il buco di prima, con un file di configurazione in piu'.
    config()->set('locker.emulator.password_hash', '');

    $this->get('/emulator')->assertUnauthorized();

    $this->post('/emulator/unlock', ['password' => ''])->assertUnauthorized();
    $this->post('/emulator/unlock', ['password' => PASSWORD])->assertUnauthorized();

    expect(session(ProtectEmulator::SESSIONE))->toBeNull();
});

it('non esiste proprio se MockPanel e\' spento', function () {
    // Il primo lucchetto regge ancora: 404, non 401. Non deve nemmeno rivelare che c'e' una porta.
    config()->set('locker.mock_panel', false);

    $this->get('/emulator')->assertNotFound();
    $this->post('/emulator/unlock', ['password' => PASSWORD])->assertNotFound();
});

it('non si fa portare fuori dall\'emulatore da un redirect arbitrario', function () {
    // Open redirect: `destinazione` arriva dalla richiesta, quindi da un estraneo.
    $this->post('/emulator/unlock', [
        'password' => PASSWORD,
        'destinazione' => 'https://sito-cattivo.example/phishing',
    ])->assertRedirect('/emulator');
});

it('richiude la porta', function () {
    $this->post('/emulator/unlock', ['password' => PASSWORD]);

    $this->post('/emulator/lock')->assertRedirect('/emulator');

    $this->get('/emulator')->assertUnauthorized();
});

/*
 * ⚠️ La trappola 30, come test: un hash $2a$ passato a Hash::check() NON risponde `false` —
 * solleva RuntimeException, e l'utente vede un 500 con la password giusta in mano.
 */
it('accetta gli hash bcrypt coniati altrove ($2a$, $2b$) senza esplodere', function () {
    $y = bcrypt(PASSWORD);

    foreach (['$2a$', '$2b$', '$2y$'] as $prefisso) {
        $hash = $prefisso.substr($y, 4);

        expect(Bcrypt::check(PASSWORD, $hash))->toBeTrue("prefisso {$prefisso}");
        expect(Bcrypt::check('sbagliata', $hash))->toBeFalse("prefisso {$prefisso}");
    }
});

it('non tratta un hash mancante o malformato come "nessuna password"', function () {
    expect(Bcrypt::check('qualunque', null))->toBeFalse();
    expect(Bcrypt::check('qualunque', ''))->toBeFalse();
    expect(Bcrypt::check('qualunque', 'non-e-un-hash'))->toBeFalse();
});
