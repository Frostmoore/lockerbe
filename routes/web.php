<?php

use App\Http\Controllers\EmulatorController;
use App\Http\Controllers\EmulatorGateController;
use App\Http\Controllers\PaymentPageController;
use App\Http\Middleware\ProtectEmulator;
use Illuminate\Support\Facades\Route;

/*
 * LA HOME — la pagina pubblica.
 *
 * ⚠️ Fino a ieri reindirizzava al pannello: era giusto quando l'unica cosa che c'era era il
 * pannello. Ma un dominio che, a chiunque passi, risponde con una schermata di login dice una
 * cosa sola — *"qui non c'è niente per te"* — e la dice anche a chi stava valutando se comprarlo.
 *
 * ⚠️ È l'unica pagina che vede **chi non è nessuno**. Quindi non contiene niente che non sia
 * pubblico: nessun conteggio di armadi, nessun nome di locale, nessuno stato di sistema. È il
 * modo tipico in cui queste pagine perdono informazioni — si aggiunge *"giusto il numero di
 * locali attivi"* per far vedere che il prodotto è vivo, e si è appena detto a un estraneo
 * quanti clienti abbiamo.
 *
 * Il link "vai al pannello" tiene conto di chi bussa: i due pannelli si respingono a vicenda, e
 * offrire il link sbagliato vorrebbe dire offrire un rimbalzo.
 */
Route::view('/', 'landing')->name('home');

/*
 * ⚠️ L'EMULATORE DEL CHIOSCO.
 *
 * Il FCV5003 non e' disponibile a data da destinarsi: questo e' l'unico modo, oggi, di vedere
 * il sistema funzionare davvero. Parla al server con lo **stesso identico contratto** del
 * dispositivo vero — stessi topic, stesso payload, stessa verifica di firma e scadenza.
 *
 * ⚠️ **TRE lucchetti, e il terzo e' arrivato tardi.**
 *
 *   1. `MockPanel`: `APP_ENV != production` **e** `LOCKER_MOCK_PANEL` acceso;
 *   2. `ProtectEmulator`: una **password**;
 *   3. rate limit sui tentativi.
 *
 * Il #2 non c'era, e il #1 da solo non bastava: il server vero, su un dominio pubblico, gira
 * `APP_ENV=staging` con il flag acceso — quindi la pagina era **aperta a chiunque**. E questa
 * pagina elenca gli armadi di *tutti* i clienti e stampa nell'HTML le credenziali MQTT e il
 * token API del chiosco (per giunta **ruotandole**, cioe' buttando offline il chiosco vero a
 * ogni caricamento).
 *
 * **Un cancello che dipende da `APP_ENV` protegge dall'ambiente, non dall'attaccante.**
 */
Route::post('/emulator/unlock', [EmulatorGateController::class, 'unlock'])
    ->middleware('throttle:5,1');   // ⚠️ senza, la password si cerca a forza bruta

Route::middleware(ProtectEmulator::class)->group(function (): void {
    Route::get('/emulator', [EmulatorController::class, 'index']);
    Route::post('/emulator/lock', [EmulatorGateController::class, 'lock']);
    Route::get('/emulator/{cabinet}', [EmulatorController::class, 'show']);
});

/*
 * LA PAGINA DI PAGAMENTO — quella che si apre sul TELEFONO del cliente, inquadrando il QR.
 *
 * ⚠️ E' qui che si chiede l'email, e non al chiosco: digitare un indirizzo su un touchscreen,
 * in un locale affollato e al buio, e' un modo affidabile di sbagliarlo — e un'email sbagliata
 * e' un cliente che non ricevera' mai il codice per riaprire il proprio vano.
 *
 * ⚠️ Nessuna autenticazione: chi deposita non ha un account. L'unica cosa che sta fra un
 * estraneo e questa pagina e' il **token pubblico** dentro il QR — e per questo il rate limit
 * e' stretto: senza, quel token si potrebbe cercare a forza bruta.
 */
Route::get('/pay/{token}', [PaymentPageController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('pay.show');

Route::post('/pay/{token}', [PaymentPageController::class, 'pay'])
    ->middleware('throttle:10,1')
    ->name('pay.pay');
