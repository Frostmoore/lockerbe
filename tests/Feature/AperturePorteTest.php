<?php

/*
 * OGNI APERTURA E OGNI CHIUSURA — E CHI L'HA FATTA.
 *
 * ⚠️ La distinzione che questi test proteggono è **una sola**, e vale tutto il resto:
 *
 *     un'apertura CON un comando dietro è legittima, e sappiamo di chi;
 *     un'apertura SENZA nessun comando dietro è un vano che si è aperto e NESSUNO ha ordinato.
 *
 * Il secondo caso è un tecnico con la chiave, la scheda serrature azionata a mano — o qualcuno
 * che sta forzando il vano di un cliente. È la riga che si va a cercare quando un cappotto non
 * c'è più. Se le due cose si somigliassero, il registro non servirebbe a niente.
 */

use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\CabinetResource\Pages\NodiCabinet;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Device;
use App\Models\Locker;
use App\Models\Tenant;
use App\Models\User;
use App\Mqtt\DeviceEventHandler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->locale = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->locale->id);

    $this->armadio = Cabinet::factory()->for($this->locale)->create(['last_seen_at' => now()]);
    $this->vano = Locker::factory()->for($this->armadio)->create(['number' => 3]);

    Device::factory()->for($this->locale)->create(['cabinet_id' => $this->armadio->id]);

    $this->tecnico = User::factory()->forTenant($this->locale)->create(['name' => 'Marta Bianchi']);
    $this->tecnico->assignRole('tenant_admin');
});

/** Il chiosco racconta un evento, come lo racconterebbe sul filo MQTT. */
function ilChioscoDice(Cabinet $armadio, array $evento): void
{
    app(DeviceEventHandler::class)->handle($armadio, $evento);
}

it('lega l\'apertura al comando che l\'ha causata', function () {
    $comando = Command::factory()->create([
        'tenant_id' => $this->locale->id,
        'cabinet_id' => $this->armadio->id,
        'locker_id' => $this->vano->id,
        'issued_by_type' => 'user',
        'issued_by_id' => $this->tecnico->id,
        'reason' => 'admin',
    ]);

    ilChioscoDice($this->armadio, ['type' => 'locker.opened', 'locker' => 3, 'command_id' => $comando->id]);

    $voce = AuditLog::withoutGlobalScopes()->where('action', 'locker.opened')->sole();

    expect($voce->command_id)->toBe($comando->id);
    expect($voce->locker_id)->toBe($this->vano->id);
});

it('⚠️⚠️ registra come APERTURA SENZA MANDANTE quella che non porta nessun comando', function () {
    // La scheda serrature ha aperto un vano che il server non ha mai chiesto.
    ilChioscoDice($this->armadio, ['type' => 'locker.opened', 'locker' => 3]);

    $voce = AuditLog::withoutGlobalScopes()->where('action', 'locker.opened')->sole();

    expect($voce->command_id)->toBeNull();
});

it('⚠️⚠️ NON crede al device se allega il comando di un ALTRO armadio', function () {
    /*
     * ⚠️ È il test che impedisce di incolpare un innocente.
     *
     * Un chiosco (per errore, o perché compromesso) potrebbe allegare l'id di un comando che
     * non è suo. Se lo prendessimo per buono, un'apertura forzata risulterebbe **ordinata da
     * Marta Bianchi** — e la riga che doveva denunciare la forzatura la coprirebbe.
     *
     * Si scarta l'id, e l'apertura resta "a mano": è la lettura prudente. Meglio un'apertura a
     * mano di troppo che una forzatura attribuita a chi non c'entra.
     */
    $altroArmadio = Cabinet::factory()->for($this->locale)->create();

    $comandoAltrui = Command::factory()->create([
        'tenant_id' => $this->locale->id,
        'cabinet_id' => $altroArmadio->id,
        'issued_by_type' => 'user',
        'issued_by_id' => $this->tecnico->id,
        'reason' => 'admin',
    ]);

    ilChioscoDice($this->armadio, ['type' => 'locker.opened', 'locker' => 3, 'command_id' => $comandoAltrui->id]);

    $voce = AuditLog::withoutGlobalScopes()->where('action', 'locker.opened')->sole();

    expect($voce->command_id)->toBeNull();   // scartato: quel comando non è di questo armadio
});

it('nel pannello dice CHI ha aperto, e dice «aperto a mano» quando non lo ha ordinato nessuno', function () {
    Filament::setCurrentPanel('app');
    $this->actingAs($this->tecnico);

    // 1. un'apertura ordinata da una persona
    $comando = Command::factory()->create([
        'tenant_id' => $this->locale->id,
        'cabinet_id' => $this->armadio->id,
        'locker_id' => $this->vano->id,
        'issued_by_type' => 'user',
        'issued_by_id' => $this->tecnico->id,
        'reason' => 'admin',
    ]);

    ilChioscoDice($this->armadio, ['type' => 'locker.opened', 'locker' => 3, 'command_id' => $comando->id]);

    // 2. un'apertura che nessuno ha ordinato
    ilChioscoDice($this->armadio, ['type' => 'locker.opened', 'locker' => 3]);

    // 3. una chiusura
    ilChioscoDice($this->armadio, ['type' => 'locker.closed', 'locker' => 3]);

    livewire(NodiCabinet::class, ['record' => $this->armadio->getKey()])
        ->assertSee('Aperture e chiusure degli sportelli')
        ->assertSee('Marta Bianchi')       // chi l'ha ordinata
        ->assertSee('dal pannello')        // e perché
        ->assertSee('aperto a mano')       // ⚠️ e quella che non ha ordinato nessuno
        ->assertSee('richiuso sullo sportello');
});

it('un\'apertura del flusso del cliente è attribuita al CLIENTE, non a un nostro utente', function () {
    Filament::setCurrentPanel('app');
    $this->actingAs($this->tecnico);

    // ⚠️ `issued_by_type = 'system'`: l'ordine è nato dal pagamento del cliente, non da una
    //    persona che ha premuto un bottone. Attribuirlo a un nostro utente sarebbe una bugia.
    $comando = Command::factory()->create([
        'tenant_id' => $this->locale->id,
        'cabinet_id' => $this->armadio->id,
        'locker_id' => $this->vano->id,
        'issued_by_type' => 'system',
        'issued_by_id' => null,
        'reason' => 'store',
    ]);

    ilChioscoDice($this->armadio, ['type' => 'locker.opened', 'locker' => 3, 'command_id' => $comando->id]);

    // ⚠️ Non si può fare `assertDontSee('aperto a mano')`: quella frase compare anche nella
    //    DESCRIZIONE della sezione, che la spiega. Si punta alla nota che esiste solo dentro
    //    una riga sospetta — altrimenti il test fallirebbe su un testo che è lì apposta.
    livewire(NodiCabinet::class, ['record' => $this->armadio->getKey()])
        ->assertSee('Cliente')
        ->assertSee('deposito')
        ->assertDontSee('nessun ordine dietro questa apertura');
});
