<?php

use App\Filament\Auth\Login;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Filament::setCurrentPanel('app');

    $this->tenant = Tenant::factory()->create();

    $this->utente = User::factory()->forTenant($this->tenant)->create([
        'username' => 'mario',
        'email' => 'mario@guardaroba.test',
        'password' => Hash::make('cavallo-batteria-graffetta'),
    ]);

    $this->utente->assignRole('tenant_staff');
});

it('lascia entrare con lo USERNAME', function () {
    // ⚠️ E' il motivo per cui questa pagina esiste. Filament, di suo, accetta solo l'email:
    // ma chi lavora al guardaroba sa a memoria il proprio username, non l'email di servizio
    // con cui gli abbiamo creato l'account.
    livewire(Login::class)
        ->fillForm(['login' => 'mario', 'password' => 'cavallo-batteria-graffetta'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($this->utente);
});

it('lascia entrare anche con l\'EMAIL', function () {
    // L'OR e' un OR: aggiungere l'username non deve togliere l'email.
    livewire(Login::class)
        ->fillForm(['login' => 'mario@guardaroba.test', 'password' => 'cavallo-batteria-graffetta'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($this->utente);
});

it('non lascia entrare con la password sbagliata', function () {
    // ⚠️ L'OR sta nella RICERCA dell'utente, non nella verifica: la password si controlla
    // sempre con l'hash. Se questo test passasse, avremmo scritto un login che fa entrare
    // chiunque conosca uno username.
    livewire(Login::class)
        ->fillForm(['login' => 'mario', 'password' => 'sbagliata'])
        ->call('authenticate')
        ->assertHasFormErrors(['login']);

    $this->assertGuest();
});

it('non lascia entrare uno username che non esiste', function () {
    livewire(Login::class)
        ->fillForm(['login' => 'nessuno', 'password' => 'cavallo-batteria-graffetta'])
        ->call('authenticate')
        ->assertHasFormErrors(['login']);

    $this->assertGuest();
});
