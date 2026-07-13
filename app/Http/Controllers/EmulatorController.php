<?php

namespace App\Http\Controllers;

use App\Domain\Device\Services\DeviceProvisioningService;
use App\Domain\Mqtt\Topics;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\User;
use App\Support\MockPanel;
use Illuminate\Contracts\View\View;
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
    public function __construct(private readonly DeviceProvisioningService $provisioning) {}

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
        $credenziali = $this->provisioning->collectCredentials($device->serial, request()->ip());

        // Il chiosco chiama le API **come device**, non come una persona: non ha ruoli, non ha
        // permessi, ha un token e un armadio (vedi ResolveTenant).
        $device->tokens()->delete();
        $apiToken = $device->createToken('kiosk')->plainTextToken;

        return view('emulator', [
            'cabinet' => $cabinet->load('lockers'),
            'device' => $device->refresh(),
            'credentials' => $credenziali,
            'apiToken' => $apiToken,
            'wsUrl' => (string) config('locker.mqtt.ws_url'),
            'topics' => [
                'cmd' => Topics::command($cabinet),
                'evt' => Topics::event($cabinet),
                'status' => Topics::status($cabinet),
            ],
            'graceSeconds' => (int) config('locker.checkout.grace'),
        ]);
    }
}
