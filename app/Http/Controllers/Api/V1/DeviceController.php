<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Device\Services\DeviceProvisioningService;
use App\Http\Resources\DeviceResource;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L'identita' di un chiosco: nascita, prova, revoca.
 *
 * ⚠️ `announce` e `credentials` sono le uniche rotte del sistema **non autenticate e non
 * tenant-scoped**, e non poteva essere altrimenti: un FCV5003 appena tolto dalla scatola non
 * ha nessuna identita' da esibire — sta chiedendo di averne una. Sono rate-limited e non
 * possono fare nulla di irreversibile: un dispositivo annunciato resta **inerte** finche' un
 * umano non lo accoppia.
 */
final class DeviceController
{
    use AuthorizesRequests;

    public function __construct(private readonly DeviceProvisioningService $provisioning) {}

    /**
     * 📣 Il chiosco si presenta. Riceve il codice da mostrare sul proprio schermo.
     *
     * ⚠️ Nessuna autenticazione: non ha ancora credenziali, e' venuto a chiederle. Cio' che
     * ottiene, pero', e' inerte: un codice a 6 cifre e nient'altro. Fino a che un operatore
     * non lo accoppia a un armadio, questo dispositivo non esiste per il sistema.
     */
    public function announce(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial' => ['required', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:40'],
            'mac_address' => ['nullable', 'string', 'max:24'],
        ]);

        ['pairing_code' => $code, 'enrollment' => $enrollment] = $this->provisioning->announce(
            (string) $data['serial'],
            $data['model'] ?? null,
            $data['mac_address'] ?? null,
            $request->ip(),
        );

        return new JsonResponse([
            'pairing_code' => $code,
            'expires_at' => $enrollment->pairing_code_expires_at?->toIso8601String(),
            'message' => 'Mostra questo codice sullo schermo. Un operatore lo digitera\' nel pannello, '
                .'davanti all\'armadio a cui sei montato.',
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * 🔑 Il chiosco ritira le credenziali, **una volta sola**.
     *
     * Finche' nessuno lo ha accoppiato: **409 `pairing_pending`** — il device continua a
     * mostrare il codice e a riprovare.
     */
    public function credentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial' => ['required', 'string', 'max:64'],
        ]);

        return new JsonResponse([
            'credentials' => $this->provisioning->collectCredentials((string) $data['serial']),
            'message' => 'Salva queste credenziali. Non verranno consegnate una seconda volta.',
        ]);
    }

    /**
     * 🔗 L'operatore accoppia il chiosco all'armadio che ha davanti.
     *
     * ⚠️ **L'unico punto del sistema in cui si decide quale chiosco comanda quale armadio**, e
     * per questo passa da un umano che sta **fisicamente davanti a quell'armadio** e legge il
     * codice **su quello schermo**. Nessun automatismo potrebbe saperlo — e legare il chiosco
     * all'armadio sbagliato significa aprire l'armadietto di uno sconosciuto a ogni richiesta,
     * senza che il software possa mai accorgersene.
     */
    public function pair(Request $request, Cabinet $cabinet): JsonResponse
    {
        $this->authorize('update', $cabinet);

        $data = $request->validate([
            'pairing_code' => ['required', 'string', 'size:6'],
        ]);

        /** @var User $user */
        $user = $request->user();

        ['device' => $device, 'credentials' => $credentials] = $this->provisioning->pair(
            $cabinet,
            (string) $data['pairing_code'],
            $user,
        );

        return new JsonResponse([
            'data' => (new DeviceResource($device))->toArray($request),
            'mqtt_client_id' => $credentials['mqtt_client_id'],
            'message' => 'Chiosco accoppiato. Ritirera\' le credenziali da solo al prossimo tentativo.',
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * ♻️ Ri-abilita un chiosco che ha perso le credenziali (reflash, factory reset, OTA finito male).
     *
     * ⚠️ Passa da un umano **per costruzione**: il server non puo' distinguere un chiosco che
     * ha davvero perso la memoria da un impostore che ne conosce il serial. Chi conferma sta
     * dicendo "quel dispositivo li', quello attaccato a quell'armadio, e' quello vero" — ed e'
     * l'unico al mondo in grado di dirlo.
     */
    public function reissue(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device->cabinet()->firstOrFail());

        /** @var User $user */
        $user = $request->user();

        $this->provisioning->reissueCredentials($device, $user);

        return new JsonResponse([
            'data' => (new DeviceResource($device->refresh()))->toArray($request),
            'message' => 'Credenziali rigenerate. Il chiosco le ritirera\' al prossimo tentativo.',
        ]);
    }

    /**
     * ⛔ Revoca: chiosco rubato, clonato o sostituito.
     *
     * ⚠️ E' la sola difesa reale contro un dispositivo compromesso — il FCV5003 non ha un
     * secure element, quindi il segreto nella sua memoria e' estraibile da chi ce l'ha in
     * mano. Non ci si difende: ci si accorge, e si revoca.
     */
    public function revoke(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device->cabinet()->firstOrFail());

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->provisioning->revoke($device, $user, (string) $data['reason']);

        return new JsonResponse([
            'data' => (new DeviceResource($device->refresh()))->toArray($request),
        ]);
    }
}
