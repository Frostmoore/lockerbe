<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mqtt\Topics;
use App\Domain\Tenancy\TenantContext;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⚠️ **IL CONFINE TRA CLIENTI SUL CANALE REALTIME** (piano §3.3).
 *
 * Il broker non tiene nessun elenco di utenti: a ogni connessione e a ogni pubblicazione
 * chiede **a noi**. Questi tre endpoint sono la sua unica fonte di verita'.
 *
 * ⚠️ Perche' non i classici file `passwd` + `acl` di mosquitto: perche' le credenziali dei
 * chioschi **nascono e muoiono a runtime** (il tecnico preme "Attiva"; un chiosco rubato viene
 * revocato). Con file statici, una revoca avrebbe effetto solo dopo un rigenera-e-ricarica —
 * cioe', in pratica, "quando qualcuno se ne ricorda". Chiedendo al server, invece, **la revoca
 * ha effetto immediato**: il chiosco revocato non si connette piu', punto.
 *
 * ⚠️ E l'ACL non e' un dettaglio di configurazione: e' cio' che impedisce a un chiosco di
 * sottoscrivere `locker/#` e ricevere — quindi **eseguire** — i comandi di apertura di tutti
 * gli armadi di tutti i locali.
 *
 * Queste rotte sono **interne al broker**: non sono autenticate (il broker non ha un token) e
 * vanno raggiungibili solo dalla rete del broker. In produzione: rete privata, non esposte.
 */
final class MqttAuthController
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * "Questo client, con questa password, puo' connettersi?"
     *
     * 200 = si'. Qualunque altro codice = no.
     */
    public function user(Request $request): JsonResponse
    {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        // Il nostro server e' un client come gli altri: ha un'identita' e dei permessi, e
        // NON e' superuser. Se un giorno le sue credenziali finissero in mano sbagliata,
        // l'attaccante potrebbe pubblicare comandi — ma il device li scarterebbe comunque,
        // perche' non saprebbe firmarli (§ CommandSigner).
        if ($this->isServer($username)) {
            return hash_equals((string) config('locker.mqtt.server_password'), $password)
                ? $this->yes()
                : $this->no();
        }

        $device = $this->findDevice($username);

        if ($device === null || $device->credential_fingerprint === null) {
            return $this->no();
        }

        // ⚠️ Confronto a tempo costante: un confronto normale perde informazione sul
        // segreto attraverso il tempo di risposta.
        if (! hash_equals($device->credential_fingerprint, hash('sha256', $password))) {
            return $this->no();
        }

        return $this->yes();
    }

    /**
     * "E' un superuser?" — **Mai.**
     *
     * ⚠️ Un superuser MQTT scavalcherebbe **tutte** le ACL, cioe' l'intero confine tra
     * clienti. Non ne esistono, e non devono esistere: il nostro server non passa dal broker
     * come superuser, si connette come client normale con i propri permessi.
     */
    public function superuser(): JsonResponse
    {
        return $this->no();
    }

    /**
     * "Questo client puo' leggere/scrivere su questo topic?"
     *
     * ⚠️ **Nessuna wildcard, mai.** Un chiosco puo':
     *   - **sottoscrivere** solo `.../cab/{il-suo}/cmd`
     *   - **pubblicare** solo su `.../cab/{il-suo}/evt` e `.../cab/{il-suo}/status`
     *
     * Tutto il resto — compreso il topic dell'armadio accanto, nello stesso locale — e' no.
     */
    public function acl(Request $request): JsonResponse
    {
        $username = (string) $request->input('username', '');
        $topic = (string) $request->input('topic', '');

        // mosquitto-go-auth: 1=read(subscribe), 2=write(publish), 4=subscribe.
        $acc = (int) $request->input('acc', 0);

        /*
         * Il server: permessi **speculari** a quelli di un chiosco.
         *   - pubblica sui `cmd` (manda comandi)
         *   - sottoscrive `evt` e `status` (ascolta cio' che i device raccontano)
         *
         * Non il contrario: il server non deve poter pubblicare finti eventi al posto di un
         * device, ne' sottoscrivere i propri comandi.
         */
        if ($this->isServer($username)) {
            // ⚠️ Il server sottoscrive con WILDCARD (`locker/t/+/cab/+/evt`): il broker
            // controlla l'ACL sul pattern, non sul topic concreto. Va ammesso anche il `+`,
            // altrimenti il listener non riesce nemmeno a sottoscrivere.
            if (preg_match('#^locker/t/([^/]+)/cab/([^/]+)/(cmd|evt|status)$#', $topic, $m) !== 1) {
                return $this->no();
            }

            $suffisso = $m[3];

            $permesso = match (true) {
                $acc === 2 => $suffisso === 'cmd',
                in_array($acc, [1, 4], true) => in_array($suffisso, ['evt', 'status'], true),
                default => false,
            };

            return $permesso ? $this->yes() : $this->no();
        }

        $device = $this->findDevice($username);

        if ($device === null || $device->cabinet_id === null || $device->isRevoked()) {
            return $this->no();
        }

        $parsed = Topics::parse($topic);

        if ($parsed === null) {
            return $this->no();
        }

        // ⚠️ Il topic deve essere ESATTAMENTE quello del suo armadio.
        if ($parsed['cabinet'] !== $device->cabinet_id || $parsed['tenant'] !== $device->tenant_id) {
            return $this->no();
        }

        $suffisso = substr($topic, strrpos($topic, '/') + 1);

        // In lettura (subscribe) puo' avere solo i comandi. In scrittura, solo eventi e stato.
        $permesso = match (true) {
            in_array($acc, [1, 4], true) => $suffisso === 'cmd',
            $acc === 2 => in_array($suffisso, ['evt', 'status'], true),
            default => false,
        };

        return $permesso ? $this->yes() : $this->no();
    }

    private function isServer(string $username): bool
    {
        return $username !== '' && $username === (string) config('locker.mqtt.server_username');
    }

    /** Il broker chiede prima di conoscere il tenant: la ricerca gira in bypass. */
    private function findDevice(string $mqttClientId): ?Device
    {
        if ($mqttClientId === '') {
            return null;
        }

        return $this->context->runWithBypass(
            fn (): ?Device => Device::query()
                ->where('mqtt_client_id', $mqttClientId)
                ->whereNot('status', 'revoked')
                ->first(),
        );
    }

    private function yes(): JsonResponse
    {
        return new JsonResponse(['Ok' => true]);
    }

    private function no(): JsonResponse
    {
        return new JsonResponse(['Ok' => false], JsonResponse::HTTP_UNAUTHORIZED);
    }
}
