<?php

namespace App\Http\Controllers;

use App\Domain\Device\Services\DeviceProvisioningService;
use App\Domain\Mqtt\Topics;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\User;
use App\Support\MockPanel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * L'EMULATORE DEL CHIOSCO.
 *
 * ⚠️ Il FCV5003 **non e' disponibile a data da destinarsi**. Questo e' l'unico modo che
 * abbiamo, oggi, di vedere il sistema funzionare davvero — e non e' un giocattolo: parla al
 * server **con lo stesso identico contratto** del dispositivo vero. Stessi topic MQTT, stesso
 * payload, stessa verifica della firma, stesso rifiuto dei comandi scaduti.
 *
 * Non e' un finto device: e' **un device diverso** che implementa lo stesso protocollo.
 *
 * ⚠️ **Cio' che NON dimostra**: lo scriviamo noi, quindi conferma le nostre stesse assunzioni.
 * Non valida il protocollo RS-485 (D5), ne' i limiti di dxUi, ne' il comportamento del client
 * MQTT di DejaOS su rete instabile. Elimina il rischio **server** e il rischio **contratto**,
 * non il rischio **hardware**: quando il device tornera', FH sara' comunque una fase vera.
 *
 * ⚠️ **Doppio cancello** (MockPanel): fuori da production, e solo col flag acceso. Questa
 * pagina riceve le credenziali MQTT del chiosco in chiaro, dentro l'HTML.
 */
final class EmulatorController extends Controller
{
    public function __construct(
        private readonly DeviceProvisioningService $provisioning,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * L'elenco degli armadi accesi.
     *
     * ⚠️ Esiste per un motivo banale e reale: nessuno sa a memoria l'uuid di un armadio. Senza
     * questa pagina, per aprire l'emulatore bisognerebbe andare a pescare un id nel database —
     * e uno strumento che si usa solo dopo una query non lo usa nessuno.
     *
     * Scavalca l'isolamento tra clienti (`runWithBypass`) perche' e' una pagina da banco di
     * lavoro, non da esercizio: qui i clienti non esistono, esistono gli armadi accesi. Puo'
     * farlo **solo** perche' e' dietro il doppio cancello.
     */
    public function index(): View
    {
        if (! MockPanel::enabled()) {
            throw new NotFoundHttpException;
        }

        /** @var Collection<int, Cabinet> $armadi */
        $armadi = $this->tenants->runWithBypass(
            fn () => Cabinet::query()
                ->has('device')
                ->with(['device', 'tenant', 'lockers'])
                ->orderBy('code')
                ->get()
        );

        return view('emulator-index', ['cabinets' => $armadi]);
    }

    public function show(Cabinet $cabinet): View
    {
        if (! MockPanel::enabled()) {
            throw new NotFoundHttpException;
        }

        $device = $cabinet->device()->first();

        if (! $device instanceof Device) {
            throw new NotFoundHttpException('Questo armadio non ha ancora un chiosco.');
        }

        /*
         * L'emulatore ha bisogno delle credenziali MQTT del chiosco. Il device vero le ritira
         * una volta sola e le salva in memoria; l'emulatore, che vive in una pagina web e non
         * ha memoria, se le fa dare a ogni caricamento.
         *
         * ⚠️ E' una scorciatoia legittima **solo** perche' siamo dietro il doppio cancello:
         * questa pagina non esiste in produzione.
         */
        /** @var User $attore */
        $attore = auth()->user() ?? User::query()->whereNotNull('tenant_id')->firstOrFail();

        $this->provisioning->activate($device, $attore);

        /*
         * ⚠️ L'EMULATORE USA ESATTAMENTE LE CREDENZIALI DEL DEVICE VERO. Prima no, e la
         * differenza aveva nascosto una lacuna grossa.
         *
         * Il token delle API HTTP se lo coniava da solo — cosa che poteva permettersi perche'
         * gira **dentro** il server. Il FCV5003 non ha nessuno che lo faccia per lui, e quel
         * token non era nel payload di attivazione: il porting si sarebbe fermato li', col
         * device in mano e il tecnico che aspetta.
         *
         * Ora `api_token` e i `topics` arrivano da `collectCredentials()`, cioe' **dalla stessa
         * porta da cui li ritirera' il chiosco vero**. Se un giorno mancasse di nuovo qualcosa,
         * l'emulatore si rompe subito — che e' l'unico modo di accorgersene in tempo.
         */
        $credenziali = $this->provisioning->collectCredentials($device->serial, request()->ip());

        return view('emulator', [
            'cabinet' => $cabinet->load('lockers'),
            'device' => $device->refresh(),
            'credentials' => $credenziali,
            'apiToken' => $credenziali['api_token'],
            'wsUrl' => (string) config('locker.mqtt.ws_url'),
            'topics' => $credenziali['topics'],
            'graceSeconds' => (int) config('locker.checkout.grace'),
        ]);
    }
}
