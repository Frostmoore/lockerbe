<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CabinetController;
use App\Http\Controllers\Api\V1\CommandController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\KioskController;
use App\Http\Controllers\Api\V1\LockerController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\MockController;
use App\Http\Controllers\Api\V1\MqttAuthController;
use App\Http\Controllers\Api\V1\PlatformSettingController;
use App\Http\Controllers\Api\V1\PublicSessionController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Support\MockPanel;
use Illuminate\Support\Facades\Route;

/*
 * API v1 (piano §10). Prefisso `/api/v1`, impostato in bootstrap/app.php.
 *
 * ORDINE DEI MIDDLEWARE — non e' cosmetico:
 *
 *   auth:sanctum  autentica; per farlo deve poter CERCARE l'utente, quindi gira ancora
 *                 in bypass (EstablishTenantContext): a quel punto non sappiamo ancora
 *                 a che tenant appartenga chi sta bussando.
 *   tenant        (ResolveTenant) stringe il contesto sul tenant dell'utente. Da qui in
 *                 poi l'isolamento e' armato: RLS + global scope.
 *   mfa           (EnsureMfaSatisfied) blocca chi dovrebbe avere il secondo fattore e
 *                 non ce l'ha.
 *
 * Invertire i primi due significa non poter fare login. Dimenticare `tenant` su una
 * rotta significa lasciarla in bypass — cioe' un utente di un locale che vede, e domani
 * apre, gli armadi di un altro.
 */

// Il login e' l'unica porta: non si lascia bussare all'infinito.
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Esenti da `mfa`: chi deve ancora configurare il secondo fattore deve poter
    // raggiungere queste rotte, altrimenti resterebbe chiuso fuori dall'unica porta che
    // gli permette di rientrare.
    Route::post('auth/mfa/enroll', [MfaController::class, 'enroll']);
    Route::post('auth/mfa/confirm', [MfaController::class, 'confirm']);
    Route::delete('auth/mfa', [MfaController::class, 'disable']);

    Route::middleware('mfa')->group(function (): void {
        Route::get('platform/settings', [PlatformSettingController::class, 'index'])
            ->middleware('can:tenant.manage');

        Route::patch('platform/settings', [PlatformSettingController::class, 'update'])
            ->middleware('can:tenant.manage');

        /*
         * Inventario (F2). L'autorizzazione fine sta nelle Policy (CabinetPolicy,
         * LockerPolicy); l'isolamento tra clienti NON e' qui — lo fanno global scope e RLS,
         * e un armadio di un altro tenant produce 404 gia' nel route-model binding.
         *
         * Nota: NON esiste ancora `POST /lockers/{id}/open`. L'apertura arriva in F4,
         * insieme al TTL e al rifiuto verso gli armadi offline: prima le difese, poi l'arma.
         */
        Route::get('cabinets', [CabinetController::class, 'index']);
        Route::post('cabinets', [CabinetController::class, 'store']);
        Route::get('cabinets/{cabinet}', [CabinetController::class, 'show']);
        Route::patch('cabinets/{cabinet}', [CabinetController::class, 'update']);
        Route::get('cabinets/{cabinet}/lockers', [CabinetController::class, 'lockers']);

        /*
         * Il chiosco (FCV5003). Fisicamente e' tutt'uno con l'armadio: lamiera, serrature e
         * uno schermo avvitato in mezzo. Il tecnico lo registra sul SERVER col serial letto
         * dall'etichetta, lo lega all'armadio, e preme Attiva.
         *
         * ⚠️ `activate` e' anche il bottone della RI-ABILITAZIONE: stesso click, nuovo segreto,
         * stesso armadio. Un solo gesto da imparare.
         */
        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::post('devices/{device}/attach', [DeviceController::class, 'attach']);
        Route::post('devices/{device}/activate', [DeviceController::class, 'activate']);
        Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke']);

        Route::get('lockers/{locker}', [LockerController::class, 'show']);
        Route::patch('lockers/{locker}', [LockerController::class, 'update']);

        /*
         * ⚠️ L'APERTURA DEI VANI. La superficie piu' pericolosa del sistema.
         *
         *   - 202, non 200: il comando e' preso in carico, NON eseguito. L'esito arriva
         *     con l'ack del device.
         *   - 409 se l'armadio e' offline, e nessun comando viene creato: non si accoda una
         *     promessa di apertura (§8.4).
         *   - Idempotency-Key obbligatoria: un retry di rete non deve aprire due volte.
         */
        Route::post('lockers/{locker}/open', [CommandController::class, 'open'])
            ->middleware('can:locker.open');

        Route::get('commands/{command}', [CommandController::class, 'show']);

        // ⚠️⚠️ Svuota il guardaroba. Non e' di tenant_staff, richiede conferma e motivazione.
        Route::post('cabinets/{cabinet}/open-all', [CommandController::class, 'openAll'])
            ->middleware('can:locker.open_all');

        // Sessioni (F3).
        Route::get('sessions', [SessionController::class, 'index'])->middleware('can:session.view');
        Route::post('sessions', [SessionController::class, 'store'])->middleware('can:session.view');
        Route::get('sessions/{session}', [SessionController::class, 'show'])->middleware('can:session.view');
        Route::post('sessions/{session}/checkout', [SessionController::class, 'checkout'])
            ->middleware('can:session.checkout');

        // ⚠️ La conferma che il vano e' VUOTO. Solo dopo questa (o dopo lo sportello
        // richiuso, o allo scadere della finestra) il vano torna assegnabile.
        Route::post('sessions/{session}/checkout/confirm', [SessionController::class, 'confirmCheckout'])
            ->middleware('can:session.checkout');
    });
});

/*
 * ⚠️ IL BROKER CHE CI CHIEDE CHI PUO' FARE COSA (piano §3.3, §9).
 *
 * Non autenticate: il broker non ha un token. Sono **interne**, e in produzione vanno
 * raggiungibili solo dalla sua rete.
 *
 * ⚠️ Sono il confine tra clienti sul canale realtime. Un chiosco che potesse sottoscrivere
 * `locker/#` riceverebbe — quindi eseguirebbe — i comandi di apertura di TUTTI gli armadi di
 * TUTTI i locali. Il broker chiede a noi, e noi diciamo no.
 */
Route::prefix('mqtt')->group(function (): void {
    Route::post('user', [MqttAuthController::class, 'user']);
    Route::post('superuser', [MqttAuthController::class, 'superuser']);
    Route::post('acl', [MqttAuthController::class, 'acl']);
});

/*
 * IL CHIOSCO CHE RITIRA LE PROPRIE CREDENZIALI.
 *
 * ⚠️ Non autenticata, e non poteva essere altrimenti: il chiosco non ha ancora nulla da
 * esibire, e' venuto a prendersela. Ma **non e' un ignoto**: il server sa gia' chi e' quel
 * serial, glielo ha detto un tecnico quando ha registrato il dispositivo.
 *
 * Consegna solo dentro la **finestra di attivazione** — un gesto umano, deliberato e a tempo.
 * Fuori dalla finestra, chiunque bussi con quel serial non ottiene niente.
 */
Route::post('devices/credentials', [DeviceController::class, 'credentials'])
    ->middleware('throttle:20,1');

/*
 * ⚠️ LE API DEL CHIOSCO — autenticate **come device**, non come persona.
 *
 * Il chiosco non ha ruoli ne' permessi: ha un token e un armadio. E il cliente che ci sta
 * davanti **non ha un account** — non deve fare login per depositare un cappotto.
 *
 * ⚠️ Il chiosco agisce **solo sul proprio armadio**, e non lo sceglie: glielo dice la sua
 * stessa identita' (ResolveTenant + KioskController::cabinetOf). Un chiosco che potesse
 * indicare un `cabinet_id` a piacere sarebbe un chiosco che, compromesso, apre gli armadi
 * degli altri.
 */
Route::middleware(['auth:sanctum', 'tenant'])->prefix('kiosk')->group(function (): void {
    Route::get('state', [KioskController::class, 'state']);
    Route::post('sessions', [KioskController::class, 'requestLocker']);

    /*
     * ⚠️ Il chiosco chiede com'e' finita la sessione che sta servendo (pagata? rifiutata?).
     *
     * Prima interrogava `/public/sessions/{token}`, che ha un rate limit STRETTO — 10 al minuto,
     * perche' quel token e' l'unica cosa che separa un estraneo dal cappotto di qualcun altro.
     * Il chiosco ne faceva 30 al minuto: dopo venti secondi scattava il 429 e il chiosco
     * restava MUTO per sempre. Il cliente vedeva la schermata di pagamento e nient'altro.
     *
     * Il chiosco e' autenticato come device: non deve passare dalla porta di servizio pensata
     * per un estraneo con un token in mano.
     */
    Route::get('sessions/{session}', [KioskController::class, 'sessionStatus']);

    // ⚠️ Il cliente ha cambiato idea: il vano torna libero SUBITO. Non e' cortesia, e'
    // inventario — senza, un ripensamento blocca un vano per tutta la prenotazione.
    Route::post('sessions/{session}/cancel', [KioskController::class, 'cancelSession']);
});

/*
 * PUBBLICHE — il cliente che ha depositato il cappotto (piano §10).
 *
 * ⚠️ Nessuna autenticazione: chi deposita non ha un account (§4). Ha solo il token che gli
 * e' stato dato al momento del pagamento. Il tenant si ricava DAL TOKEN — vedi
 * PublicSessionController::resolveSession().
 *
 * ⚠️ Rate limit stretto: quel token e' l'unica cosa che separa un estraneo dal cappotto di
 * qualcun altro. Senza limite, lo si potrebbe cercare a forza bruta.
 */
Route::prefix('public/sessions')->middleware('throttle:10,1')->group(function (): void {
    Route::get('{token}', [PublicSessionController::class, 'show']);
    Route::post('{token}/reopen', [PublicSessionController::class, 'reopen']);
    Route::post('{token}/checkout', [PublicSessionController::class, 'checkout']);
});

/*
 * I BOTTONI (piano §12) — pagamento e carta simulati.
 *
 * ⚠️ DOPPIO CANCELLO: esistono solo fuori da production E con `locker.mock_panel` acceso.
 * In produzione queste rotte non devono nemmeno esistere (404, non 403). C'e' un test.
 *
 * Sono l'unico modo, oggi, di vedere girare il flusso completo: il FCV5003 non e'
 * disponibile, Nexi non ha dato le credenziali, le carte NFC non esistono ancora.
 */
if (MockPanel::enabled()) {
    Route::middleware(['auth:sanctum', 'tenant'])->prefix('mock')->group(function (): void {
        Route::post('payments/{payment}/confirm', [MockController::class, 'confirmPayment']);
        Route::post('payments/{payment}/fail', [MockController::class, 'failPayment']);
        Route::post('identity/tap', [MockController::class, 'tapCard']);

        /*
         * IL DEVICE SIMULATO (piano §12.3).
         *
         * ⚠️ `heartbeat` e' **il primo bottone da premere**: senza, l'armadio risulta offline e
         * ogni apertura risponde 409. Sembrera' un bug, e non lo e' — e' la difesa contro il
         * rischio #1 che fa il suo mestiere.
         */
        Route::post('devices/{cabinet}/heartbeat', [MockController::class, 'heartbeat']);
        Route::post('commands/{command}/ack', [MockController::class, 'ack']);
    });
}
