<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\PlatformSettingController;
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
    });
});
