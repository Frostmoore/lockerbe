<?php

use App\Domain\Identity\Contracts\IdentityProvider;
use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Mail\CodiceAccesso;
/*
 * ⚠️ `assertQueued`, non `assertSent`.
 *
 * L'email col codice si **accoda**, non si spedisce in linea: parte dentro la transazione che
 * conferma il pagamento, e un SMTP che tossisce faceva rollback dell'incasso — 500 al cliente e
 * vano bloccato su `reserved`. (Ci e' successo davvero: vedi `PagamentoQrTest`.)
 *
 * Se un giorno qualcuno tornasse a `Mail::send()`, questi test tornerebbero rossi. E' voluto.
 */
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Device;
use App\Models\Identity;
use App\Models\Locker;
use App\Models\Session;
use App\Models\Tenant;
use App\Mqtt\DeviceEventHandler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ⚠️⚠️ L'IDENTITA' NASCE DAL PAGAMENTO.
 *
 * E' il cuore di questo file, ed e' un cambio di rotta. Prima l'identita' si attaccava dopo,
 * con un tap "quando capita": chi pagava col QR non ne riceveva **nessuna**, poi premeva "ho
 * finito" e non succedeva niente — il vano restava occupato, e dal punto di vista del cliente
 * il sistema era rotto.
 *
 *   QR   → paga sul telefono, lascia l'email, riceve un **codice a 6 cifre**
 *   NFC  → paga con la carta, e il **token che il provider restituisce** e' lo scontrino
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create();
    $this->cabinet = Cabinet::factory()->forTenant($this->tenant)->online()->create();
    Device::factory()->forCabinet($this->cabinet)->create();

    foreach ([1, 2] as $n) {
        Locker::factory()->forCabinet($this->cabinet)->create([
            'number' => $n, 'board_address' => 1, 'channel' => $n,
        ]);
    }

    $this->handler = app(DeviceEventHandler::class);

    RateLimiter::clear('identity:'.$this->cabinet->id);
});

/**
 * Il cliente chiede un vano. Non ha ancora pagato.
 *
 * @return array{session: Session, token: string}
 */
function chiediVano(Cabinet $cabinet, string $metodo): array
{
    /** @var array{session: Session, token: string} */
    return app(TenantContext::class)->runForTenant(
        $cabinet->tenant_id,
        fn (): array => app(SessionManager::class)->request($cabinet, null, $metodo),
    );
}

/**
 * Il chiosco manda un evento, come farebbe il FCV5003 via MQTT.
 *
 * @param  array<string, mixed>  $payload
 */
function evento(Cabinet $cabinet, array $payload): void
{
    test()->handler->handle($cabinet, $payload);
}

/*
 * ═══ QR: paga sul telefono, ricevi un codice per email ═══
 */

it('⚠️ manda un codice a 6 cifre per email quando si paga col QR', function () {
    Mail::fake();

    ['session' => $sessione, 'token' => $token] = chiediVano($this->cabinet, 'qr');

    // ⚠️ L'email la chiede la PAGINA di pagamento, non il chiosco: digitare un indirizzo su un
    // touchscreen, in un locale affollato e al buio, e' un modo affidabile di sbagliarlo — e
    // un'email sbagliata e' un cliente che non ricevera' mai il codice.
    $this->post("/pay/{$token}", ['email' => 'cliente@esempio.it'])
        ->assertRedirect("/pay/{$token}");

    $sessione->refresh();

    expect($sessione->status)->toBe('active')
        ->and($sessione->customer_email)->toBe('cliente@esempio.it')
        // ⚠️ L'identita' esiste GIA': e' nata col pagamento, non con un tap successivo.
        ->and($sessione->identities()->where('type', 'access_code')->count())->toBe(1);

    Mail::assertQueued(CodiceAccesso::class, fn (CodiceAccesso $m): bool => $m->hasTo('cliente@esempio.it')
        && preg_match('/^\d{6}$/', $m->codice) === 1);
});

it('⚠️ nel database non finisce mai il codice, solo il suo hash', function () {
    Mail::fake();

    ['token' => $token] = chiediVano($this->cabinet, 'qr');

    $this->post("/pay/{$token}", ['email' => 'cliente@esempio.it']);

    $codice = '';
    Mail::assertQueued(CodiceAccesso::class, function (CodiceAccesso $m) use (&$codice): bool {
        $codice = $m->codice;

        return true;
    });

    // ⚠️ Chi legge il database non deve poter aprire i vani.
    $this->assertDatabaseMissing('identities', ['token_hash' => $codice]);
    $this->assertDatabaseHas('identities', ['token_hash' => Identity::hashToken($codice)]);
});

it('riconsegna col codice digitato al chiosco', function () {
    Mail::fake();

    ['session' => $sessione, 'token' => $token] = chiediVano($this->cabinet, 'qr');
    $this->post("/pay/{$token}", ['email' => 'cliente@esempio.it']);

    $codice = '';
    Mail::assertQueued(CodiceAccesso::class, function (CodiceAccesso $m) use (&$codice): bool {
        $codice = $m->codice;

        return true;
    });

    // ⚠️ L'intento si dichiara PRIMA di identificarsi (§7.1): "ho finito" vuol dire finito, e
    // non si perde per strada.
    evento($this->cabinet, ['type' => 'identity.presented', 'token' => $codice, 'intent' => 'checkout']);

    $sessione->refresh();

    // Il vano non torna libero adesso: si apre, e aspetta la conferma che e' vuoto.
    expect($sessione->checkout_pending_at)->not->toBeNull()
        ->and($sessione->locker()->firstOrFail()->status)->toBe('checkout')
        ->and(Command::query()->where('reason', 'checkout')->count())->toBe(1);
});

/*
 * ═══ NFC: la carta paga, e la carta e' lo scontrino ═══
 */

it('⚠️ il token della carta lo da\' il PROVIDER, e diventa l\'identita\'', function () {
    ['session' => $sessione] = chiediVano($this->cabinet, 'nfc');

    // Il chiosco presenta la carta. ⚠️ A dire che i soldi sono arrivati — e con che token — e'
    // il PROVIDER: se bastasse la parola del chiosco, un chiosco compromesso potrebbe
    // dichiarare "questa carta ha pagato" e regalarsi tutti i vani dell'armadio.
    evento($this->cabinet, [
        'type' => 'payment.card',
        'session_id' => $sessione->id,
        'card_token' => 'TOK-CARTA-DI-MARIO',
    ]);

    $sessione->refresh();

    expect($sessione->status)->toBe('active')
        ->and($sessione->payment_method)->toBe('nfc')
        ->and($sessione->identities()->where('type', 'nfc_card')->count())->toBe(1);

    // E la stessa carta, riappoggiata, riapre il vano. Nessun altro scontrino.
    expect(app(IdentityProvider::class)->resolve('TOK-CARTA-DI-MARIO', $this->cabinet)?->id)
        ->toBe($sessione->id);
});

it('⚠️ non lascia al chiosco pagare una sessione che non e\' sua', function () {
    $altroTenant = Tenant::factory()->create();
    $altroCabinet = Cabinet::factory()->forTenant($altroTenant)->online()->create();
    Device::factory()->forCabinet($altroCabinet)->create();
    Locker::factory()->forCabinet($altroCabinet)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    ['session' => $altrui] = chiediVano($altroCabinet, 'nfc');

    // ⚠️ Il `session_id` arriva dalla rete: non ci si fida, si verifica. Un chiosco compromesso
    // che indicasse la sessione di un altro locale se la prenderebbe.
    evento($this->cabinet, [
        'type' => 'payment.card',
        'session_id' => $altrui->id,
        'card_token' => 'TOK-LADRO',
    ]);

    expect($altrui->refresh()->status)->toBe('created');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'payment.card',
        'error_code' => 'no_session_to_pay',
    ]);
});

/*
 * ═══ CIO' CHE NON DEVE SUCCEDERE ═══
 */

it('⚠️ non apre NIENTE con un\'identita\' sconosciuta', function () {
    Mail::fake();

    ['token' => $token] = chiediVano($this->cabinet, 'qr');
    $this->post("/pay/{$token}", ['email' => 'cliente@esempio.it']);

    // ⚠️ L'asimmetria (§7.0): davanti a un dubbio, un vano non si tocca. Il vecchio codice
    // faceva il CONTRARIO — legava d'ufficio un token sconosciuto a una sessione a caso, e con
    // due clienti di fila la carta del primo apriva il vano del secondo.
    evento($this->cabinet, ['type' => 'identity.presented', 'token' => '000000', 'intent' => 'checkout']);

    expect(Command::query()->where('reason', 'checkout')->count())->toBe(0);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'identity.unmatched',
        'error_code' => 'unknown_identity',
    ]);
});

it('⚠️⚠️ blocca l\'armadio dopo troppi codici sbagliati', function () {
    /*
     * ⚠️ Sei cifre sono un milione di combinazioni: **da sole sarebbero poche**. Senza un
     * freno, un pomeriggio di tentativi automatici le prova tutte, e il codice smette di essere
     * una chiave.
     *
     * Il freno vive PER ARMADIO, perche' e' li' che l'attacco avviene: davanti a quella
     * lamiera, con quel touchscreen. E si somma alle altre due difese: il codice vale solo per
     * QUELL'armadio, e solo finche' la sessione e' viva.
     */
    foreach (range(1, 6) as $i) {
        evento($this->cabinet, [
            'type' => 'identity.presented',
            'token' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'intent' => 'reopen',
        ]);
    }

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'identity.throttled',
        'error_code' => 'too_many_attempts',
    ]);
});

it('registra il caso in cui il cliente paga e non lascia l\'email', function () {
    Mail::fake();

    ['session' => $sessione] = chiediVano($this->cabinet, 'qr');

    // Il pagamento confermato "da fuori" (un webhook, lo staff) senza che l'email sia arrivata.
    app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($sessione): void {
        app(SessionManager::class)->confirmPayment($sessione->payment()->firstOrFail());
    });

    // ⚠️ Ha pagato e non potra' riaprire il vano da solo. Non e' un caso teorico — e' un campo
    // di form saltato — e lo staff deve poterlo scoprire dal registro, invece di sentirselo
    // dire dal cliente.
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'identity.issue',
        'error_code' => 'no_customer_email',
    ]);

    Mail::assertNothingSent();
});

/*
 * ═══ UNA CARTA, UN VANO ═══
 */

it('⚠️⚠️ non lascia prendere un SECONDO vano con la stessa carta — e non incassa', function () {
    ['session' => $primo] = chiediVano($this->cabinet, 'nfc');

    evento($this->cabinet, [
        'type' => 'payment.card',
        'session_id' => $primo->id,
        'card_token' => 'LA-MIA-CARTA',
    ]);

    expect($primo->refresh()->status)->toBe('active');

    // Stessa carta, secondo vano.
    ['session' => $secondo] = chiediVano($this->cabinet, 'nfc');

    evento($this->cabinet, [
        'type' => 'payment.card',
        'session_id' => $secondo->id,
        'card_token' => 'LA-MIA-CARTA',
    ]);

    /*
     * ⚠️ Rifiutato, e **senza incassare**: il controllo sta PRIMA della chiamata al provider.
     * Farlo dopo significherebbe aver preso i soldi di un cliente a cui poi diciamo di no.
     *
     * Perché una carta tiene un vano solo:
     *  - se ne aprisse due, chi la trovasse per terra avrebbe le chiavi di entrambi;
     *  - e al tap il sistema non saprebbe *quale* aprire: la risoluzione prende la sessione più
     *    recente, quindi il PRIMO vano — pagato, pieno — diventerebbe irraggiungibile con la
     *    sua stessa carta.
     */
    $secondo->refresh();

    expect($secondo->status)->toBe('cancelled')
        ->and($secondo->paid_at)->toBeNull()
        // Il vano riservato torna libero, invece di restare bloccato fino al timeout.
        ->and($secondo->locker()->firstOrFail()->status)->toBe('free');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'payment.card',
        'error_code' => 'card_already_in_use',
    ]);
});

it('⚠️ e il PRIMO vano resta apribile con la sua carta', function () {
    ['session' => $primo] = chiediVano($this->cabinet, 'nfc');

    evento($this->cabinet, ['type' => 'payment.card', 'session_id' => $primo->id, 'card_token' => 'LA-MIA-CARTA']);

    ['session' => $secondo] = chiediVano($this->cabinet, 'nfc');
    evento($this->cabinet, ['type' => 'payment.card', 'session_id' => $secondo->id, 'card_token' => 'LA-MIA-CARTA']);

    // ⚠️ È il vero danno che il tentativo poteva fare: la carta che smette di aprire il vano in
    // cui c'è il cappotto del cliente. Non deve succedere nemmeno per un istante.
    expect(app(IdentityProvider::class)->resolve('LA-MIA-CARTA', $this->cabinet)?->id)
        ->toBe($primo->id);
});

it('lascia riusare la carta DOPO aver riconsegnato', function () {
    ['session' => $primo] = chiediVano($this->cabinet, 'nfc');
    evento($this->cabinet, ['type' => 'payment.card', 'session_id' => $primo->id, 'card_token' => 'LA-MIA-CARTA']);

    // Riconsegna completa: intento dichiarato, poi la carta. Poi lo sportello richiuso.
    evento($this->cabinet, ['type' => 'identity.presented', 'token' => 'LA-MIA-CARTA', 'intent' => 'checkout']);
    evento($this->cabinet, ['type' => 'locker.closed', 'locker' => $primo->locker()->firstOrFail()->number]);

    expect($primo->refresh()->status)->toBe('closed');

    // ⚠️ La carta è di nuovo libera: il vincolo è "un vano ALLA VOLTA", non "un vano per sempre".
    // Una carta bruciata dopo il primo uso sarebbe una carta inutile.
    ['session' => $secondo] = chiediVano($this->cabinet, 'nfc');
    evento($this->cabinet, ['type' => 'payment.card', 'session_id' => $secondo->id, 'card_token' => 'LA-MIA-CARTA']);

    expect($secondo->refresh()->status)->toBe('active')
        ->and(app(IdentityProvider::class)->resolve('LA-MIA-CARTA', $this->cabinet)?->id)->toBe($secondo->id);
});

it('⚠️ dice al chiosco che la sessione e\' stata RIFIUTATA, senza rate limit', function () {
    ['session' => $primo] = chiediVano($this->cabinet, 'nfc');
    evento($this->cabinet, ['type' => 'payment.card', 'session_id' => $primo->id, 'card_token' => 'LA-MIA-CARTA']);

    ['session' => $secondo] = chiediVano($this->cabinet, 'nfc');
    evento($this->cabinet, ['type' => 'payment.card', 'session_id' => $secondo->id, 'card_token' => 'LA-MIA-CARTA']);

    $device = $this->cabinet->device()->firstOrFail();
    $token = $device->createToken('kiosk')->plainTextToken;

    /*
     * ⚠️ IL BUG CHE QUESTA ROTTA HA CHIUSO.
     *
     * Il chiosco chiedeva lo stato a `/public/sessions/{token}`, che ha un rate limit stretto —
     * 10 al minuto — perché quel token è l'unica cosa che separa un estraneo dal cappotto di
     * qualcun altro. Ma il chiosco lo interrogava ogni 2 secondi: 30 al minuto. Dopo venti
     * secondi scattava il 429, il fetch riceveva un corpo d'errore invece dello stato, e il
     * chiosco RESTAVA MUTO PER SEMPRE — il cliente vedeva la schermata di pagamento e nient'altro.
     *
     * Il chiosco è autenticato COME DEVICE: non deve passare dalla porta di servizio pensata per
     * un estraneo con un token in mano. Trenta richieste di fila devono funzionare.
     */
    foreach (range(1, 30) as $i) {
        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/kiosk/sessions/{$secondo->id}")
            ->assertOk();
    }

    expect($r->json('status'))->toBe('cancelled');
});

it('⚠️ non lascia al chiosco guardare una sessione di un altro armadio', function () {
    $altroTenant = Tenant::factory()->create();
    $altroCabinet = Cabinet::factory()->forTenant($altroTenant)->online()->create();
    Device::factory()->forCabinet($altroCabinet)->create();
    Locker::factory()->forCabinet($altroCabinet)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    ['session' => $altrui] = chiediVano($altroCabinet, 'qr');

    $device = $this->cabinet->device()->firstOrFail();
    $token = $device->createToken('kiosk')->plainTextToken;

    // ⚠️ L'id arriva dalla rete. Un chiosco compromesso non deve poter spiare le sessioni di un
    // altro locale — nemmeno solo per leggerne lo stato.
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson("/api/v1/kiosk/sessions/{$altrui->id}")
        ->assertNotFound();
});
