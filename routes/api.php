<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CabinetController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\LockerController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\MockController;
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

        // Identita' del chiosco: accoppiamento, ri-abilitazione, revoca.
        // ⚠️ `pair` e' l'unico punto in cui si decide quale chiosco comanda quale armadio.
        Route::post('cabinets/{cabinet}/pair', [DeviceController::class, 'pair']);
        Route::post('devices/{device}/reissue', [DeviceController::class, 'reissue']);
        Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke']);

        Route::get('lockers/{locker}', [LockerController::class, 'show']);
        Route::patch('lockers/{locker}', [LockerController::class, 'update']);

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
 * IL CHIOSCO CHE CHIEDE UN'IDENTITA'.
 *
 * ⚠️ Le uniche rotte non autenticate e non tenant-scoped del sistema, e non poteva essere
 * altrimenti: un FCV5003 appena tolto dalla scatola non ha nessuna identita' da esibire —
 * sta chiedendo di averne una.
 *
 * Cio' che ottengono e' pero' **inerte**: un codice a sei cifre da mostrare a schermo. Finche'
 * un operatore non lo accoppia a un armadio — stando fisicamente davanti a quell'armadio e
 * leggendo il codice su quello schermo — il dispositivo non esiste per il sistema.
 */
Route::prefix('devices')->middleware('throttle:20,1')->group(function (): void {
    Route::post('announce', [DeviceController::class, 'announce']);
    Route::post('credentials', [DeviceController::class, 'credentials']);
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
    });
}
