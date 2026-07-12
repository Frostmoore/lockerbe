<?php

use App\Support\MockPanel;
use Illuminate\Support\Facades\Route;

/*
 * Il doppio cancello sui bottoni mock (piano §12).
 *
 * ⚠️ In produzione queste rotte non devono ESSERE PROTETTE: non devono ESISTERE. Dietro c'e'
 * un endpoint che conferma un pagamento senza che nessuno abbia pagato — in produzione,
 * un modo gratuito di farsi aprire un armadietto. Percio' 404, non 403.
 */

it('registra i bottoni mock in sviluppo', function () {
    $rotteMock = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_contains($uri, 'mock/'));

    expect(MockPanel::enabled())->toBeTrue()
        ->and($rotteMock)->not->toBeEmpty();
});

it('chiude il cancello se mock_panel e\' spento', function () {
    config(['locker.mock_panel' => false]);

    expect(MockPanel::enabled())->toBeFalse();
});

it('chiude il cancello in production, ANCHE col flag acceso', function () {
    // I due lucchetti sono indipendenti: in produzione vince l'ambiente, sempre.
    config(['locker.mock_panel' => true]);
    app()->detectEnvironment(fn (): string => 'production');

    expect(MockPanel::enabled())->toBeFalse();
});
