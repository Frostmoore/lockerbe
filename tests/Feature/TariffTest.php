<?php

use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\Locker;
use App\Models\Session;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * IL PREZZO DI UN VANO — **a cascata**: armadio → locale → default di piattaforma.
 *
 * ⚠️ Sempre in **centesimi**. I float non tengono i soldi (`0.1 + 0.2 !== 0.3`), e un
 * guardaroba che sbaglia un centesimo mille volte a sera lo sbaglia in bilancio.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::factory()->create(['settings' => ['tariff_cents' => 800]]);
});

/** Un vano preso su questo armadio: quanto costa? */
function prezzoDi(Cabinet $cabinet): int
{
    Device::factory()->forCabinet($cabinet)->create();
    Locker::factory()->forCabinet($cabinet)->create(['number' => 1, 'board_address' => 1, 'channel' => 1]);

    /** @var Session $sessione */
    $sessione = app(TenantContext::class)->runForTenant(
        $cabinet->tenant_id,
        fn (): Session => app(SessionManager::class)->request($cabinet)['session'],
    );

    return $sessione->amount_cents;
}

it('usa il prezzo DELL\'ARMADIO quando ce l\'ha', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['tariff_cents' => 1200]);

    // ⚠️ Il prezzo è una proprietà del *posto in cui metti la roba*, non dell'azienda: l'armadio
    // all'ingresso e quello in fondo al corridoio possono costare diverso.
    expect(prezzoDi($armadio))->toBe(1200);
});

it('eredita il prezzo DEL LOCALE quando l\'armadio non ne ha uno', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['tariff_cents' => null]);

    expect(prezzoDi($armadio))->toBe(800);
});

it('⚠️ e il null NON è una copia: ritoccare il listino aggiorna gli armadi che lo seguono', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['tariff_cents' => null]);

    // Il gestore ritocca il listino del locale.
    $this->tenant->forceFill(['settings' => ['tariff_cents' => 1000]])->save();

    /*
     * ⚠️ È il motivo per cui l'armadio conserva un `null` invece di una copia del prezzo del
     * locale. Copiarlo "per comodità" al momento della creazione lo congelerebbe al prezzo di
     * quel giorno: il gestore ritocca il listino, gli armadi che non ha toccato restano al
     * prezzo vecchio, e nessuno se ne accorge finché non arriva un cliente a lamentarsi.
     */
    expect(prezzoDi($armadio->refresh()))->toBe(1000);
});

it('ripiega sul default di piattaforma se nessuno ha deciso niente', function () {
    $senzaListino = Tenant::factory()->create(['settings' => []]);
    $armadio = Cabinet::factory()->forTenant($senzaListino)->online()->create(['tariff_cents' => null]);

    // 5,00 €. Un default esiste perché un vano senza prezzo è un vano che si regala.
    expect(prezzoDi($armadio))->toBe(500);
});

it('lascia mettere un vano GRATIS, e non lo confonde con "non deciso"', function () {
    $armadio = Cabinet::factory()->forTenant($this->tenant)->online()->create(['tariff_cents' => 0]);

    // ⚠️ Zero e null sono due cose diverse: zero è "gratis" (una serata promozionale, il
    // guardaroba del personale), null è "non ho deciso, segui il locale". Un `?:` al posto di un
    // `!== null` li appiattirebbe, e il vano gratis costerebbe 8 euro.
    expect(prezzoDi($armadio))->toBe(0);
});
