<?php

use App\Http\Controllers\EmulatorController;
use App\Http\Controllers\PaymentPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * ⚠️ L'EMULATORE DEL CHIOSCO.
 *
 * Il FCV5003 non e' disponibile a data da destinarsi: questo e' l'unico modo, oggi, di vedere
 * il sistema funzionare davvero. Parla al server con lo **stesso identico contratto** del
 * dispositivo vero — stessi topic, stesso payload, stessa verifica di firma e scadenza.
 *
 * Doppio cancello (MockPanel): non esiste in produzione. La pagina riceve le credenziali MQTT
 * del chiosco dentro l'HTML.
 */
Route::get('/emulator', [EmulatorController::class, 'index']);
Route::get('/emulator/{cabinet}', [EmulatorController::class, 'show']);

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
