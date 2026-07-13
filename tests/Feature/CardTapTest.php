<?php

use App\Domain\Identity\Contracts\IdentityProvider;
use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Device;
use App\Models\Locker;
use App\Models\Payment;
use App\Models\Session;
use App\Models\Tenant;
use App\Mqtt\DeviceEventHandler;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * IL TAP DELLA CARTA — quello che il cliente fa davvero, al chiosco.
 *
 * ⚠️ La carta si presenta **al DEPOSITO**: e' li' che il pezzo di plastica diventa lo
 * scontrino del vano. E l'intento ("riapri" / "ho finito") si dichiara **prima** di
 * passarla (§7.1): e' l'unica cosa che distingue *"torno a prendere il telefono"* da
 * *"me ne vado"*.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create();
    $this->cabinet = Cabinet::factory()->forTenant($this->tenant)->online()->create();
    Device::factory()->forCabinet($this->cabinet)->create();

    Locker::factory()->forCabinet($this->cabinet)->create([
        'number' => 1, 'board_address' => 1, 'channel' => 1,
    ]);

    Locker::factory()->forCabinet($this->cabinet)->create([
        'number' => 2, 'board_address' => 1, 'channel' => 2,
    ]);

    $this->handler = app(DeviceEventHandler::class);
});

/**
 * Un cliente prende un vano e paga. La carta non l'ha ancora passata.
 *
 * ⚠️ Dentro `runForTenant`: nella vita vera il contesto lo mette `ResolveTenant` a ogni
 * richiesta, e `DeviceEventHandler` lo mette da sé per ogni evento del chiosco. Un test che
 * chiama i servizi a nudo deve fare quel pezzo di lavoro — altrimenti nasce una sessione
 * senza tenant, che è il record che nessuna policy RLS può proteggere.
 */
function deposita(Cabinet $cabinet): Session
{
    /** @var Session */
    return app(TenantContext::class)->runForTenant($cabinet->tenant_id, function () use ($cabinet): Session {
        $istruzione = app(SessionManager::class)->request($cabinet);

        /** @var Session $sessione */
        $sessione = $istruzione['session'];

        // ⚠️ Il Payment lo crea il chiosco (KioskController), non SessionManager: il dominio
        // non sa niente di provider di pagamento, e non deve. Qui rifacciamo quel pezzo.
        $pagamento = Payment::create([
            'session_id' => $sessione->id,
            'provider' => 'mock',
            'provider_ref' => 'test-'.$sessione->id,
            'amount_cents' => $sessione->amount_cents,
            'currency' => 'EUR',
            'status' => 'created',
            'payload' => [],
        ]);

        $sessione->forceFill(['payment_id' => $pagamento->id])->save();

        return app(SessionManager::class)->confirmPayment($pagamento);
    });
}

/** Il tap della carta, come arriva dal chiosco via MQTT. (`tap` e' gia' un helper di Laravel.) */
function passaCarta(Cabinet $cabinet, string $token, string $intent, ?Session $sessione = null): void
{
    test()->handler->handle($cabinet, array_filter([
        'type' => 'identity.presented',
        'token' => $token,
        'intent' => $intent,
        'session_id' => $sessione?->id,
    ]));
}

it('lega la carta al DEPOSITO: è lì che diventa lo scontrino', function () {
    $sessione = deposita($this->cabinet);

    // ⚠️ Prima questo passaggio non esisteva: il cliente pagava col QR e la carta non gli
    // veniva mai chiesta. Poi premeva "ho finito", la carta risultava sconosciuta, e non
    // succedeva NIENTE — il vano restava occupato, e dal suo punto di vista era rotto.
    passaCarta($this->cabinet, 'CARTA-001', 'store', $sessione);

    expect(app(IdentityProvider::class)->resolve('CARTA-001', $this->cabinet)?->id)
        ->toBe($sessione->id);

    // ⚠️ Legare la carta non apre NULLA di nuovo: l'unico comando è quello del deposito, che
    // era gia' partito col pagamento (il vano si apre per farci mettere dentro il cappotto).
    expect(Command::query()->count())->toBe(1)
        ->and(Command::query()->where('reason', 'store')->count())->toBe(1);
});

it('⚠️ fa il checkout quando il cliente dice "ho finito"', function () {
    $sessione = deposita($this->cabinet);
    passaCarta($this->cabinet, 'CARTA-001', 'store', $sessione);

    passaCarta($this->cabinet, 'CARTA-001', 'checkout');

    $sessione->refresh();
    $vano = $sessione->locker()->firstOrFail();

    // ⚠️ Il vano NON torna libero adesso: si apre, e aspetta la conferma che è vuoto
    // (sportello richiuso, o scadenza della finestra). Un'azione ambigua non libera un vano.
    expect($vano->status)->toBe('checkout')
        ->and($sessione->checkout_pending_at)->not->toBeNull()
        ->and(Command::query()->where('reason', 'checkout')->count())->toBe(1);
});

it('riapre quando il cliente dice "riapri" — e il vano resta suo', function () {
    $sessione = deposita($this->cabinet);
    passaCarta($this->cabinet, 'CARTA-001', 'store', $sessione);

    passaCarta($this->cabinet, 'CARTA-001', 'reopen');

    $sessione->refresh();

    // ⚠️ Riaprire non è andarsene: la sessione resta attiva, il vano resta assegnato a lui.
    expect($sessione->status)->toBe('active')
        ->and($sessione->reopen_count)->toBe(1)
        ->and($sessione->checkout_pending_at)->toBeNull()
        ->and(Command::query()->where('reason', 'reopen')->count())->toBe(1);
});

it('⚠️⚠️ non lascia che la carta di un cliente apra il vano di un ALTRO', function () {
    /*
     * ⚠️ IL BUCO CHE QUESTO CHIUDE.
     *
     * Il vecchio rattoppo legava una carta sconosciuta "alla sessione attiva PIU' RECENTE
     * dell'armadio". Due clienti depositano di fila; il secondo passa la carta per primo e si
     * lega alla propria (la più recente: giusto). Poi arriva il PRIMO: la sua carta è
     * sconosciuta, e la "più recente attiva" è ancora quella del secondo — **e il primo
     * cliente si apriva il vano del secondo**.
     *
     * Ora la sessione la dice il chiosco: è lui che, un istante fa, ha mostrato il QR a quella
     * persona. Il server non indovina.
     */
    $primo = deposita($this->cabinet);
    $secondo = deposita($this->cabinet);

    passaCarta($this->cabinet, 'CARTA-B', 'store', $secondo);
    passaCarta($this->cabinet, 'CARTA-A', 'store', $primo);

    $identita = app(IdentityProvider::class);

    expect($identita->resolve('CARTA-A', $this->cabinet)?->id)->toBe($primo->id)
        ->and($identita->resolve('CARTA-B', $this->cabinet)?->id)->toBe($secondo->id);
});

it('⚠️ non apre NIENTE con una carta sconosciuta', function () {
    deposita($this->cabinet);

    // ⚠️ L'asimmetria (§7.0): davanti a un dubbio, un vano non si tocca. Prima, una carta
    // sconosciuta veniva legata d'ufficio a una sessione a caso — cioè il contrario.
    passaCarta($this->cabinet, 'CARTA-DI-NESSUNO', 'checkout');

    // Nessun comando di riconsegna: resta solo quello del deposito.
    expect(Command::query()->where('reason', 'checkout')->count())->toBe(0);

    // E finisce nel registro: è esattamente ciò che vede il cliente a cui "non succede nulla".
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'identity.unmatched',
        'error_code' => 'no_session_for_card',
    ]);
});

it('⚠️ non lascia al chiosco legare una carta a una sessione che non è sua', function () {
    $altroTenant = Tenant::factory()->create();
    $altroCabinet = Cabinet::factory()->forTenant($altroTenant)->online()->create();
    Device::factory()->forCabinet($altroCabinet)->create();
    Locker::factory()->forCabinet($altroCabinet)->create([
        'number' => 1, 'board_address' => 1, 'channel' => 1,
    ]);

    $altrui = deposita($altroCabinet);

    // ⚠️ Il `session_id` arriva dalla rete: non ci si fida, si verifica. Un chiosco
    // compromesso che indicasse la sessione di un altro locale si legherebbe — e poi
    // aprirebbe — il vano di un altro cliente.
    passaCarta($this->cabinet, 'CARTA-LADRA', 'store', $altrui);

    expect(app(IdentityProvider::class)->resolve('CARTA-LADRA', $altroCabinet))->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'identity.bind',
        'error_code' => 'no_session_to_bind',
    ]);
});
