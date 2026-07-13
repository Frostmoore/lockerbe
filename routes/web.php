<?php

use App\Http\Controllers\EmulatorController;
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
