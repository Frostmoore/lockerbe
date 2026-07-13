<?php

namespace App\Domain\Device\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Device\Exceptions\PairingException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * L'identita' di un chiosco: come nasce, come si dimostra, come si revoca.
 *
 * ═══ IL FLUSSO, COME AVVIENE IN CAMPO ═══
 *
 *  1. L'armadio arriva in sede. E' **un oggetto solo**: lamiera, sportelli, serrature, e un
 *     FCV5003 avvitato in mezzo.
 *  2. Il tecnico **registra il dispositivo sul server**, leggendone il `serial` dall'etichetta.
 *  3. Il tecnico **crea l'armadio** (nome, numero di vani) e lo lega a quel dispositivo.
 *  4. Il tecnico preme **Attiva**. Il chiosco, acceso, si presenta e ritira le sue credenziali.
 *  5. Fine. I riavvii successivi sono trasparenti.
 *
 * ═══ PERCHE' NON SERVE UN CODICE DA LEGGERE SULLO SCHERMO ═══
 *
 * Perche' **il server sa gia' chi e'**: gliel'ha detto un umano al passo 2, prima ancora che il
 * dispositivo venisse acceso. Non c'e' nessun chiosco misterioso da identificare, e nessun
 * rischio di legarlo all'armadio sbagliato: e' il tecnico che ha scritto quel serial accanto a
 * quell'armadio.
 *
 * Cio' che resta da garantire e' solo che le credenziali finiscano **nel dispositivo giusto** e
 * non in mano a un impostore che conosce il serial. Da qui la **finestra di attivazione**: un
 * gesto umano, deliberato e a tempo. Fuori dalla finestra, chiunque bussi con quel serial —
 * foss'anche il chiosco vero — non ottiene niente.
 *
 * ═══ RI-ABILITAZIONE: UN CLICK ═══
 *
 * Il chiosco ha perso la memoria (reflash, factory reset, OTA finito male)? Il tecnico preme
 * **Attiva**. Stesso bottone, stesso gesto. Nuovo segreto, stesso armadio.
 *
 * ═══ IL MODELLO DI MINACCIA, REALISTICO ═══
 *
 * ⚠️ **Non stiamo difendendo il segreto dentro il device, e sarebbe inutile provarci.** Chi
 * riesce a staccare dal muro uno schermo avvitato dentro un armadio ha gia' in mano l'armadio,
 * i vani e i cappotti. A quel punto la memoria del dispositivo e' l'ultimo dei problemi.
 *
 * Il confine che difendiamo e' **quello di rete**:
 *   - credenziali **per-device**: compromettere un chiosco non compromette gli altri;
 *   - **ACL limitate al proprio armadio** (F5): un chiosco rubato comanda solo l'armadio da cui
 *     e' stato staccato — cioe' uno gia' fisicamente compromesso. Non apre nient'altro, in
 *     nessun altro locale;
 *   - **revoca**: ci si accorge e si spegne.
 */
final class DeviceProvisioningService
{
    /** Quanto resta aperta la finestra di attivazione. Il tempo di accendere il chiosco. */
    private const ACTIVATION_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Passo 2 — Il tecnico registra il dispositivo leggendo il serial dall'etichetta.
     *
     * L'armadio puo' non esistere ancora: si lega dopo (o si crea gia' legato).
     */
    public function register(string $serial, ?string $model, ?Cabinet $cabinet, User $by): Device
    {
        $device = Device::create([
            'cabinet_id' => $cabinet?->id,
            'serial' => $serial,
            'model' => $model ?? 'VF203_V12',
            // L'identificativo con cui si presentera' al broker (F5). Nasce qui, non lo
            // inventa il dispositivo: un ID auto-generato sarebbe una dichiarazione, non una
            // prova.
            'mqtt_client_id' => 'dev-'.Str::lower(Str::random(10)),
            'status' => 'registered',
        ]);

        $this->audit->log('device.registered', [
            'cabinet_id' => $cabinet?->id,
            'actor' => $by,
            'context' => ['serial' => $serial, 'model' => $device->model],
        ]);

        return $device;
    }

    /** Lega un dispositivo gia' registrato a un armadio (o lo sposta, se libero). */
    public function attachToCabinet(Device $device, Cabinet $cabinet, User $by): Device
    {
        if ($cabinet->device()->whereKeyNot($device->id)->exists()) {
            throw new PairingException(
                'cabinet_already_paired',
                'Questo armadio ha gia\' un altro chiosco. Revocalo prima di associarne un altro.',
            );
        }

        $device->forceFill(['cabinet_id' => $cabinet->id])->save();

        $this->audit->log('device.attached', [
            'cabinet_id' => $cabinet->id,
            'actor' => $by,
            'context' => ['serial' => $device->serial, 'cabinet_code' => $cabinet->code],
        ]);

        return $device;
    }

    /**
     * Passo 4 — **Attiva**: apre la finestra e prepara le credenziali.
     *
     * ⚠️ E' un gesto umano e deliberato: il tecnico lo fa quando il chiosco e' li', montato e
     * acceso. E' anche il bottone della **ri-abilitazione**: stesso click, nuovo segreto,
     * stesso armadio. Un solo gesto da imparare.
     *
     * ⚠️ Il segreto vecchio smette di valere **solo qui**, non prima: cosi' nessuno puo'
     * buttare fuori un chiosco vero limitandosi a bussare col suo serial.
     */
    public function activate(Device $device, User $by): Device
    {
        if ($device->cabinet_id === null) {
            throw new PairingException(
                'device_without_cabinet',
                'Questo dispositivo non e\' ancora legato a un armadio.',
            );
        }

        if ($device->isRevoked()) {
            throw new PairingException(
                'device_revoked',
                'Questo dispositivo e\' stato revocato e non puo\' essere riattivato.',
            );
        }

        return DB::transaction(function () use ($device, $by): Device {
            $secret = Str::random(48);

            $credentials = [
                'device_id' => $device->id,
                'cabinet_id' => (string) $device->cabinet_id,
                'mqtt_client_id' => $device->mqtt_client_id,
                'mqtt_secret' => $secret,
            ];

            $device->forceFill([
                // Sul server resta solo l'IMPRONTA del segreto, mai il segreto.
                'credential_fingerprint' => hash('sha256', $secret),
                'credentials_payload' => json_encode($credentials, JSON_THROW_ON_ERROR),
                'credentials_delivered_at' => null,
                'activation_expires_at' => now()->addMinutes(self::ACTIVATION_WINDOW_MINUTES),
                'activated_at' => now(),
                'activated_by' => $by->id,
                'status' => 'provisioned',
            ])->save();

            $this->audit->log('device.activated', [
                'cabinet_id' => $device->cabinet_id,
                'actor' => $by,
                'context' => [
                    'serial' => $device->serial,
                    'finestra_minuti' => self::ACTIVATION_WINDOW_MINUTES,
                ],
            ]);

            return $device;
        });
    }

    /**
     * Il chiosco si accende e ritira le credenziali. **Una volta sola, e solo nella finestra.**
     *
     * ⚠️ Non e' autenticato — non ha ancora nulla da esibire, e' venuto a prendersela. Ma non
     * e' nemmeno un ignoto: il server sa gia' chi e' quel serial, glielo ha detto un tecnico.
     *
     * @return array<string, string>
     */
    public function collectCredentials(string $serial, ?string $ip): array
    {
        return $this->context->runWithBypass(function () use ($serial, $ip): array {
            $device = Device::query()->where('serial', $serial)->first();

            if ($device === null) {
                // Serial sconosciuto: nessuno lo ha registrato. Non diciamo altro.
                throw new PairingException(
                    'unknown_device',
                    'Dispositivo non registrato. Un tecnico deve inserirlo nel server.',
                );
            }

            if ($device->isRevoked()) {
                throw new PairingException('device_revoked', 'Dispositivo revocato.');
            }

            if (! $device->hasOpenActivationWindow()) {
                /*
                 * ⚠️ Fuori dalla finestra. Puo' essere il chiosco vero che ha perso la memoria
                 * — oppure un impostore che conosce il serial. Il server **non puo'
                 * distinguerli**, e quindi non ci prova: chiede a un umano di premere "Attiva".
                 *
                 * Le credenziali attuali restano intatte: se le invalidassimo qui, chiunque
                 * conoscesse un serial potrebbe buttare fuori un chiosco vero limitandosi a
                 * bussare.
                 */
                $this->audit->log('device.activation_requested', [
                    'cabinet_id' => $device->cabinet_id,
                    'actor_type' => 'device',
                    'result' => 'fail',
                    'error_code' => 'activation_closed',
                    'context' => ['serial' => $serial, 'ip' => $ip],
                ]);

                throw new PairingException(
                    'activation_closed',
                    'Nessuna attivazione in corso per questo dispositivo. '
                    .'Un tecnico deve premere "Attiva" nel pannello.',
                );
            }

            if ($device->credentials_payload === null) {
                throw new PairingException(
                    'credentials_already_collected',
                    'Credenziali gia\' consegnate. Serve una nuova attivazione.',
                );
            }

            /** @var array<string, string> $credentials */
            $credentials = json_decode((string) $device->credentials_payload, true, 512, JSON_THROW_ON_ERROR);

            $device->forceFill([
                'credentials_payload' => null,
                'credentials_delivered_at' => now(),
                'activation_expires_at' => null,   // la finestra si chiude col ritiro
                'ip_address' => $ip,
            ])->save();

            $this->audit->log('device.credentials_collected', [
                'cabinet_id' => $device->cabinet_id,
                'actor_type' => 'device',
                'context' => ['serial' => $serial, 'ip' => $ip],
            ]);

            return $credentials;
        });
    }

    /**
     * Revoca: chiosco rubato, guasto, o da sostituire.
     *
     * ⚠️ Non serve a proteggere il segreto dentro il device — quello e' indifendibile, e non
     * importa: chi stacca il chiosco dal muro ha gia' l'armadio. Serve a chiudere il **confine
     * di rete**: da qui quelle credenziali non valgono piu' niente e (da F5) il broker non lo
     * lascia nemmeno connettere.
     */
    public function revoke(Device $device, User $by, string $reason): void
    {
        $device->forceFill([
            'status' => 'revoked',
            'credential_fingerprint' => null,
            'credentials_payload' => null,
            'activation_expires_at' => null,
        ])->save();

        $this->audit->log('device.revoked', [
            'cabinet_id' => $device->cabinet_id,
            'actor' => $by,
            'context' => ['serial' => $device->serial, 'reason' => $reason],
        ]);
    }
}
