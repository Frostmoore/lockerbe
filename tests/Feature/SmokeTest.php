<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('risponde all\'health check', function () {
    $this->get('/up')->assertOk();
});

it('gira su PostgreSQL, non su SQLite', function () {
    // Se questo test diventa rosso, i test di isolamento di F1 non valgono piu' nulla:
    // SQLite non ha la Row Level Security e le policy sarebbero inerti (piano §3.2).
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('esegue le query come locker_app: non superuser e senza bypass RLS', function () {
    // Il ruolo di runtime NON deve poter scavalcare le policy RLS. Se un giorno
    // qualcuno mettesse credenziali da superuser nel .env, l'isolamento fra tenant
    // sparirebbe in silenzio: questo test e' la sveglia.
    $role = DB::selectOne('SELECT current_user AS role');
    $flags = DB::selectOne(
        'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user'
    );

    expect($role->role)->toBe('locker_app')
        ->and($flags->rolsuper)->toBeFalse()
        ->and($flags->rolbypassrls)->toBeFalse();
});

it('assegna agli utenti una PK uuid v7', function () {
    $user = User::factory()->create();

    expect($user->id)->toBeString()
        // La versione e' il primo carattere del terzo gruppo: 019f586c-3a76-'7'0c7-...
        ->and($user->id[14])->toBe('7');
});
