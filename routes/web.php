<?php

use App\Http\Controllers\EmulatorController;
use App\Http\Controllers\EmulatorGateController;
use App\Http\Controllers\PaymentPageController;
use App\Http\Middleware\ProtectEmulator;
use Illuminate\Support\Facades\Route;

/*
 * LA HOME.
 *
 * ⚠️ Non c'è un sito pubblico da mostrare: LockUpWorld è un pannello e un chiosco. La pagina di
 * benvenuto di Laravel su un dominio in produzione dice una cosa sola — "qui non è ancora
 * finito niente" — e la dice a chiunque passi.
 *
 * ⚠️ La destinazione DIPENDE da chi bussa, e non è un vezzo: i due pannelli si respingono a
 * vicenda (`User::canAccessPanel()`). Un platform_admin mandato su `/app` prende un 403, e un
 * gestore di locale mandato su `/admin` pure. Mandare tutti nello stesso posto vorrebbe dire
 * che metà degli utenti sbatte contro un muro appena atterrato.
 */
Route::get('/', function () {
    $utente = auth()->user();

    if ($utente === null) {
        // Chi non è entrato va al pannello dei locali: è quello che useranno quasi tutti.
        // Filament lo dirotta da sé sul login, e dopo il login lo riporta qui.
        return redirect('/app');
    }

    return redirect($utente->isPlatformAdmin() ? '/admin' : '/app');
})->name('home');

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
