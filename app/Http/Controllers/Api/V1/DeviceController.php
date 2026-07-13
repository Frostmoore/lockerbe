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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * L'identita' di un chiosco.
 *
 * Il flusso, come avviene in campo:
 *
 *   1. l'armadio arriva (lamiera + serrature + FCV5003 avvitato in mezzo: **un oggetto solo**)
 *   2. il tecnico **registra il dispositivo** sul server, col serial letto dall'etichetta
 *   3. il tecnico **crea l'armadio** e lo lega a quel dispositivo
 *   4. il tecnico preme **Attiva** → il chiosco acceso ritira le credenziali
 *   5. fine
 *
 * ⚠️ Nessun codice da leggere sullo schermo, nessuna "anticamera" di chioschi sconosciuti: il
 * server **sa gia' chi e'**, glielo ha detto un tecnico al passo 2. Cio' che resta da garantire
 * e' solo che le credenziali finiscano nel dispositivo giusto — e a questo serve la **finestra
 * di attivazione**, che e' un gesto umano, deliberato e a tempo.
 */
final class DeviceController
{
    use AuthorizesRequests;

    public function __construct(private readonly DeviceProvisioningService $provisioning) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Cabinet::class);

        return DeviceResource::collection(
            Device::query()->with('cabinet')->orderBy('serial')->get()
        );
    }

    /**
     * 📝 Passo 2 — Il tecnico registra il dispositivo, leggendo il serial dall'etichetta.
     *
     * L'armadio puo' non esistere ancora: si lega dopo (`attach`), oppure si crea gia' legato.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Cabinet::class);

        $data = $request->validate([
            'serial' => ['required', 'string', 'max:64', 'unique:devices,serial'],
            'model' => ['nullable', 'string', 'max:40'],
            'cabinet_id' => ['nullable', 'uuid'],
        ]);

        $cabinet = isset($data['cabinet_id'])
            ? Cabinet::query()->whereKey($data['cabinet_id'])->firstOrFail()
            : null;

        /** @var User $user */
        $user = $request->user();

        $device = $this->provisioning->register(
            (string) $data['serial'],
            $data['model'] ?? null,
            $cabinet,
            $user,
        );

        return (new DeviceResource($device))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /** 🔗 Passo 3 — Lega il dispositivo all'armadio (se non erano gia' stati creati insieme). */
    public function attach(Request $request, Device $device): DeviceResource
    {
        $data = $request->validate([
            'cabinet_id' => ['required', 'uuid'],
        ]);

        $cabinet = Cabinet::query()->whereKey($data['cabinet_id'])->firstOrFail();

        $this->authorize('update', $cabinet);

        /** @var User $user */
        $user = $request->user();

        return new DeviceResource(
            $this->provisioning->attachToCabinet($device, $cabinet, $user)->load('cabinet')
        );
    }

    /**
     * ⚡ Passo 4 — **Attiva**. Apre la finestra: il chiosco acceso ritira le sue credenziali.
     *
     * ⚠️ **E' anche il bottone della ri-abilitazione.** Il chiosco ha perso la memoria (reflash,
     * factory reset, OTA finito male)? Stesso click. Nuovo segreto, stesso armadio. **Un solo
     * gesto da imparare**, non due.
     */
    public function activate(Request $request, Device $device): JsonResponse
    {
        // Il dispositivo puo' non avere ancora un armadio: in quel caso non c'e' un Cabinet su
        // cui autorizzare, e si ricade sul permesso generale di gestione. Sara' il servizio a
        // rifiutare con 409 `device_without_cabinet` — un errore parlante, non un 404 muto.
        $this->authorizeDevice($device);

        /** @var User $user */
        $user = $request->user();

        $device = $this->provisioning->activate($device, $user);

        return new JsonResponse([
            'data' => (new DeviceResource($device))->toArray($request),
            'activation_expires_at' => $device->activation_expires_at?->toIso8601String(),
            'message' => 'Attivazione aperta. Accendi il chiosco: ritirera\' le credenziali da solo.',
        ]);
    }

    /**
     * 🔑 Il chiosco ritira le credenziali. Una volta sola, e solo dentro la finestra.
     *
     * ⚠️ **Unica rotta non autenticata del sistema** insieme a quelle pubbliche: il chiosco non
     * ha ancora nulla da esibire, e' venuto a prendersela. Ma non e' un ignoto — il server sa
     * gia' chi e' quel serial.
     */
    public function credentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial' => ['required', 'string', 'max:64'],
        ]);

        return new JsonResponse([
            'credentials' => $this->provisioning->collectCredentials(
                (string) $data['serial'],
                $request->ip(),
            ),
            'message' => 'Salva queste credenziali. Non verranno consegnate una seconda volta.',
        ]);
    }

    /** Autorizza sull'armadio del dispositivo, o — se non ne ha ancora uno — sul permesso di gestione. */
    private function authorizeDevice(Device $device): void
    {
        $cabinet = $device->cabinet()->first();

        if ($cabinet instanceof Cabinet) {
            $this->authorize('update', $cabinet);

            return;
        }

        $this->authorize('create', Cabinet::class);
    }

    /** ⛔ Revoca: chiosco rubato, guasto, o da sostituire. */
    public function revoke(Request $request, Device $device): DeviceResource
    {
        $this->authorizeDevice($device);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->provisioning->revoke($device, $user, (string) $data['reason']);

        return new DeviceResource($device->refresh());
    }
}
