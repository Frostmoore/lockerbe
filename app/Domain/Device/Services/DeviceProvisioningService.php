<?php

namespace App\Domain\Device\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Device\Exceptions\PairingException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Device;
use App\Models\DeviceEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * L'identita' di un chiosco: come nasce, come si dimostra, come si revoca.
 *
 * ═══ IL PRINCIPIO ═══
 *
 * **Un ID che il device si genera da solo e' una dichiarazione, non una prova.** Se il
 * chiosco inventa un identificativo e lo manda, chiunque puo' inventarne uno e mandarlo:
 * l'ID dice "chi sono", non dimostra "sono io". La dimostrazione la fa un **segreto** — e la
 * domanda vera diventa: *chi ha dato quel segreto al device, e in base a cosa?*
 *
 * Risposta: **un essere umano, fisicamente davanti a quell'armadio.** Nessun automatismo
 * puo' sapere quale armadio ha davanti un chiosco.
 *
 * ═══ IL FLUSSO ═══
 *
 *  1. ANNUNCIO   Il chiosco non accoppiato si presenta col proprio `serial` (l'identita' che
 *                l'hardware si porta addosso). Riceve un **codice a 6 cifre** e lo mostra
 *                sullo schermo.
 *  2. ACCOPPIAM. Il tecnico, **davanti a quell'armadio**, apre il pannello, sceglie l'Armadio
 *                giusto e digita il codice che sta leggendo su **quello** schermo. E' questo
 *                che impedisce di legare il chiosco all'armadio sbagliato — un errore che il
 *                software, da solo, non potrebbe MAI accorgersi di aver commesso.
 *  3. CREDENZIALI Il server emette le credenziali MQTT per-device. Il chiosco le ritira
 *                **una volta sola** e le salva. Sul server ne resta solo l'impronta.
 *  4. RIAVVII    Trasparenti: legge le credenziali, si connette, e' lui.
 *
 * ═══ CIO' CHE NON POSSIAMO FARE, DETTO CHIARAMENTE ═══
 *
 * ⚠️ Il FCV5003 **non ha un secure element** (e' lo stesso motivo per cui l'EMV non e'
 * fattibile). Qualunque segreto salvato nella sua memoria e', in linea di principio,
 * **estraibile da chi ha il dispositivo in mano**. Contro questo non ci si difende: ci si
 * **accorge** (§ clonazione) e si **revoca**.
 *
 * ⚠️ Cio' che ci salva e' il raggio del danno: le ACL del broker legheranno quelle credenziali
 * **ai soli topic del suo armadio** (F5). Un chiosco rubato e portato altrove puo' comandare
 * soltanto l'armadio da cui e' stato staccato — cioe' uno che e' gia' fisicamente
 * compromesso. Il furto non da' accesso a nient'altro.
 */
final class DeviceProvisioningService
{
    /** Quanto vive il codice mostrato a schermo. Abbastanza per digitarlo, non per girarci intorno. */
    private const PAIRING_CODE_TTL_MINUTES = 10;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Il chiosco si presenta. Non e' autenticato: non ha ancora un'identita' da esibire.
     *
     * @return array{enrollment: DeviceEnrollment, pairing_code: string}
     *
     * @throws PairingException se il serial appartiene a un dispositivo gia' accoppiato
     */
    public function announce(string $serial, ?string $model, ?string $mac, ?string $ip): array
    {
        return $this->context->runWithBypass(function () use ($serial, $model, $mac, $ip): array {
            $device = Device::query()->where('serial', $serial)->first();

            if ($device !== null && $device->status !== 'revoked') {
                /*
                 * ⚠️ Un serial GIA' accoppiato che si ripresenta senza credenziali.
                 *
                 * Puo' essere legittimo (memoria azzerata da un reflash, un factory reset, un
                 * OTA finito male) o puo' essere un impostore che conosce il serial. Il server
                 * **non puo' distinguere i due casi**, quindi non ci prova: non ri-fida
                 * nessuno da solo. Segna la richiesta e chiede a un umano.
                 *
                 * ⚠️ Le credenziali vecchie NON vengono invalidate qui: farlo darebbe a
                 * chiunque conosca un serial il potere di buttare fuori un chiosco vero,
                 * semplicemente bussando.
                 */
                $device->forceFill(['reenrollment_requested_at' => now()])->save();

                $this->audit->log('device.reenrollment_requested', [
                    'cabinet_id' => $device->cabinet_id,
                    'actor_type' => 'device',
                    'result' => 'fail',
                    'error_code' => 'already_paired',
                    'context' => ['serial' => $serial, 'ip' => $ip],
                ]);

                throw new PairingException(
                    'already_paired',
                    'Questo dispositivo risulta gia\' accoppiato. La richiesta di ri-abilitazione '
                    .'e\' stata registrata: serve la conferma di un operatore.',
                );
            }

            $code = $this->generatePairingCode();

            $enrollment = DeviceEnrollment::query()->updateOrCreate(
                ['serial' => $serial],
                [
                    'model' => $model,
                    'mac_address' => $mac,
                    'ip_address' => $ip,
                    'pairing_code' => $code,
                    'pairing_code_expires_at' => now()->addMinutes(self::PAIRING_CODE_TTL_MINUTES),
                    'status' => 'pending',
                    'credentials_payload' => null,
                    'credentials_delivered_at' => null,
                    'device_id' => null,
                ],
            );

            $this->audit->log('device.announced', [
                'actor_type' => 'device',
                'context' => ['serial' => $serial, 'model' => $model, 'ip' => $ip],
            ]);

            return ['enrollment' => $enrollment, 'pairing_code' => $code];
        });
    }

    /**
     * Il tecnico accoppia il chiosco all'armadio che ha davanti.
     *
     * ⚠️ **Questo e' l'unico punto del sistema in cui si stabilisce quale chiosco comanda quale
     * armadio**, e per questo passa da un umano e da un codice letto su uno schermo fisico.
     * Legare il chiosco all'armadio sbagliato significa aprire l'armadietto di uno sconosciuto
     * a ogni singola richiesta, e nessun controllo automatico potrebbe rilevarlo.
     *
     * @return array{device: Device, credentials: array<string, string>}
     *
     * @throws PairingException
     */
    public function pair(Cabinet $cabinet, string $pairingCode, User $by): array
    {
        $enrollment = $this->context->runWithBypass(
            fn (): ?DeviceEnrollment => DeviceEnrollment::query()
                ->where('pairing_code', strtoupper($pairingCode))
                ->where('status', 'pending')
                ->first(),
        );

        if ($enrollment === null || ! $enrollment->hasValidPairingCode()) {
            $this->audit->log('device.pair', [
                'cabinet_id' => $cabinet->id,
                'result' => 'fail',
                'error_code' => 'invalid_pairing_code',
            ]);

            throw new PairingException(
                'invalid_pairing_code',
                'Codice di accoppiamento non valido o scaduto. Fallo rigenerare dal chiosco.',
            );
        }

        if ($cabinet->device()->exists()) {
            throw new PairingException(
                'cabinet_already_paired',
                'Questo armadio ha gia\' un chiosco. Scollegalo prima di associarne un altro.',
            );
        }

        return DB::transaction(function () use ($cabinet, $enrollment, $by): array {
            $secret = Str::random(48);
            $clientId = 'cab-'.substr($cabinet->id, 0, 8).'-'.Str::lower(Str::random(6));

            $device = Device::create([
                'cabinet_id' => $cabinet->id,
                'serial' => $enrollment->serial,
                'model' => $enrollment->model ?? 'VF203_V12',
                'mqtt_client_id' => $clientId,
                // Sul server resta solo l'IMPRONTA del segreto, mai il segreto.
                'credential_fingerprint' => hash('sha256', $secret),
                'mac_address' => $enrollment->mac_address,
                'ip_address' => $enrollment->ip_address,
                'status' => 'provisioned',
            ]);

            $device->forceFill(['paired_at' => now(), 'paired_by' => $by->id])->save();

            $credentials = [
                'device_id' => $device->id,
                'cabinet_id' => $cabinet->id,
                'mqtt_client_id' => $clientId,
                'mqtt_secret' => $secret,
            ];

            // Le credenziali restano qui, cifrate, finche' il chiosco non le ritira. Una volta
            // sola: dopo la consegna il campo si svuota e il segreto non esiste piu' da
            // nessuna parte, se non nella memoria del device.
            $enrollment->forceFill([
                'status' => 'paired',
                'device_id' => $device->id,
                'credentials_payload' => json_encode($credentials, JSON_THROW_ON_ERROR),
                'pairing_code' => null,
                'pairing_code_expires_at' => null,
            ])->save();

            $this->audit->log('device.paired', [
                'cabinet_id' => $cabinet->id,
                'actor' => $by,
                'context' => [
                    'serial' => $device->serial,
                    'mqtt_client_id' => $clientId,
                    'cabinet_code' => $cabinet->code,
                ],
            ]);

            return ['device' => $device, 'credentials' => $credentials];
        });
    }

    /**
     * Il chiosco ritira le credenziali. **Una volta sola.**
     *
     * @return array<string, string>
     *
     * @throws PairingException
     */
    public function collectCredentials(string $serial): array
    {
        return $this->context->runWithBypass(function () use ($serial): array {
            $enrollment = DeviceEnrollment::query()->where('serial', $serial)->first();

            if ($enrollment === null || $enrollment->status !== 'paired') {
                throw new PairingException(
                    'pairing_pending',
                    'Non ancora accoppiato: un operatore deve associarti a un armadio.',
                );
            }

            if ($enrollment->credentials_payload === null) {
                // Gia' ritirate. Non si consegnano due volte: se il device le ha perse, deve
                // passare dalla ri-abilitazione — cioe' da un umano.
                throw new PairingException(
                    'credentials_already_collected',
                    'Le credenziali sono gia\' state consegnate. Serve una ri-abilitazione.',
                );
            }

            /** @var array<string, string> $credentials */
            $credentials = json_decode((string) $enrollment->credentials_payload, true, 512, JSON_THROW_ON_ERROR);

            $enrollment->forceFill([
                'credentials_payload' => null,
                'credentials_delivered_at' => now(),
            ])->save();

            $this->audit->log('device.credentials_collected', [
                'actor_type' => 'device',
                'context' => ['serial' => $serial],
            ]);

            return $credentials;
        });
    }

    /**
     * Un operatore ri-abilita un dispositivo che ha perso le credenziali.
     *
     * ⚠️ Passa da un umano **per costruzione**: il server non puo' distinguere un chiosco che
     * ha davvero perso la memoria da un impostore che ne conosce il serial. Chi conferma sta
     * dicendo "quel dispositivo li', quello attaccato a quell'armadio, e' quello vero" — ed e'
     * l'unica entita' al mondo in grado di dirlo.
     *
     * @return array<string, string>
     */
    public function reissueCredentials(Device $device, User $by): array
    {
        return DB::transaction(function () use ($device, $by): array {
            $secret = Str::random(48);

            // ⚠️ Il vecchio segreto smette di valere QUI, non prima: se lo invalidassimo
            // all'arrivo della richiesta, chiunque conoscesse un serial potrebbe buttare
            // fuori un chiosco vero semplicemente bussando.
            $device->forceFill([
                'credential_fingerprint' => hash('sha256', $secret),
                'reenrollment_requested_at' => null,
                'status' => 'provisioned',
            ])->save();

            $credentials = [
                'device_id' => $device->id,
                'cabinet_id' => $device->cabinet_id,
                'mqtt_client_id' => $device->mqtt_client_id,
                'mqtt_secret' => $secret,
            ];

            $this->context->runWithBypass(function () use ($device, $credentials): void {
                DeviceEnrollment::query()->updateOrCreate(
                    ['serial' => $device->serial],
                    [
                        'status' => 'paired',
                        'device_id' => $device->id,
                        'credentials_payload' => json_encode($credentials, JSON_THROW_ON_ERROR),
                        'credentials_delivered_at' => null,
                        'pairing_code' => null,
                        'pairing_code_expires_at' => null,
                    ],
                );
            });

            $this->audit->log('device.credentials_reissued', [
                'cabinet_id' => $device->cabinet_id,
                'actor' => $by,
                'context' => ['serial' => $device->serial],
            ]);

            return $credentials;
        });
    }

    /**
     * Spegne un chiosco: rubato, clonato, o semplicemente sostituito.
     *
     * ⚠️ E' la sola difesa reale contro un dispositivo compromesso, visto che il segreto sul
     * device e' estraibile. In F5 la revoca dovra' propagarsi anche alle ACL del broker: un
     * client revocato non deve piu' potersi connettere, non solo essere segnato come tale nel
     * nostro database.
     */
    public function revoke(Device $device, User $by, string $reason): void
    {
        $device->forceFill([
            'status' => 'revoked',
            'credential_fingerprint' => null,
        ])->save();

        $this->context->runWithBypass(function () use ($device): void {
            DeviceEnrollment::query()
                ->where('serial', $device->serial)
                ->update(['status' => 'rejected', 'credentials_payload' => null]);
        });

        $this->audit->log('device.revoked', [
            'cabinet_id' => $device->cabinet_id,
            'actor' => $by,
            'context' => ['serial' => $device->serial, 'reason' => $reason],
        ]);
    }

    /** Sei cifre, senza caratteri che si confondono a schermo (0/O, 1/I). */
    private function generatePairingCode(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $code;
    }
}
