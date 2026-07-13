<?php

namespace App\Mqtt\Console;

use App\Domain\Audit\AuditLogger;
use App\Domain\Mqtt\Topics;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Mqtt\DeviceEventHandler;
use Illuminate\Console\Command;
use PhpMqtt\Client\Facades\MQTT;
use Throwable;

/**
 * Ascolta cio' che i device raccontano (piano §9).
 *
 * Va tenuto vivo da un supervisore (Supervisor, systemd, un container). ⚠️ Se muore, il server
 * smette di sapere che gli armadi sono vivi: gli heartbeat non arrivano, `MarkOfflineCabinets`
 * li porta tutti offline, e **nessun vano si apre piu'**. E' un fallimento sicuro — meglio un
 * sistema che non apre che un sistema che apre a caso — ma va monitorato.
 *
 * ⚠️ Il listener non deve MAI morire per un payload malformato: un device che manda spazzatura
 * (o un attaccante che ci prova) non deve poter spegnere il canale di tutti gli altri.
 */
final class MqttListen extends Command
{
    protected $signature = 'mqtt:listen';

    protected $description = 'Ascolta gli eventi dei device sul broker MQTT';

    public function handle(
        TenantContext $context,
        DeviceEventHandler $handler,
        AuditLogger $audit,
    ): int {
        $context->bypass();

        $mqtt = MQTT::connection();

        $this->info('In ascolto su '.Topics::allEvents().' e '.Topics::allStatus());

        $mqtt->subscribe(Topics::allEvents(), function (string $topic, string $message) use ($handler, $context): void {
            $this->onEvent($topic, $message, $handler, $context);
        }, 1);

        $mqtt->subscribe(Topics::allStatus(), function (string $topic, string $message) use ($audit, $context): void {
            $this->onStatus($topic, $message, $audit, $context);
        }, 1);

        $mqtt->loop(true);

        return self::SUCCESS;
    }

    private function onEvent(string $topic, string $message, DeviceEventHandler $handler, TenantContext $context): void
    {
        try {
            $cabinet = $this->cabinetFrom($topic, $context);

            if ($cabinet === null) {
                return;
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

            $this->line("← {$cabinet->code}: ".($payload['type'] ?? '?'));

            $handler->handle($cabinet, $payload);
        } catch (Throwable $e) {
            // ⚠️ Non si muore per un messaggio storto: un device che manda spazzatura non deve
            // poter spegnere il canale di tutti gli altri.
            report($e);
            $this->error('Evento scartato: '.$e->getMessage());
        }
    }

    /**
     * La **LWT** (Last Will and Testament): il testamento del chiosco.
     *
     * ⚠️ E' l'unico modo di sapere che un device e' morto **subito**, invece di aspettare che
     * scada l'heartbeat. Il device lo deposita al broker quando si connette; se la connessione
     * cade in modo sporco (corrente staccata, cavo tirato), il broker lo pubblica al posto suo.
     *
     * Un armadio che risulta offline entro un secondo invece che entro novanta e' un armadio
     * che smette **subito** di ricevere comandi di apertura.
     */
    private function onStatus(string $topic, string $message, AuditLogger $audit, TenantContext $context): void
    {
        try {
            $cabinet = $this->cabinetFrom($topic, $context);

            if ($cabinet === null) {
                return;
            }

            $online = trim($message) === 'online';

            $context->runForTenant($cabinet->tenant_id, function () use ($cabinet, $online, $audit): void {
                if ($cabinet->status !== 'maintenance') {
                    $cabinet->forceFill([
                        'status' => $online ? 'online' : 'offline',
                        'last_seen_at' => $online ? now() : $cabinet->last_seen_at,
                    ])->save();
                }

                $cabinet->device()->first()?->forceFill([
                    'status' => $online ? 'online' : 'offline',
                ])->save();

                if (! $online) {
                    $audit->log('device.went_offline', [
                        'cabinet_id' => $cabinet->id,
                        'actor_type' => 'device',
                        'result' => 'fail',
                        'error_code' => 'lwt',
                        'context' => ['testamento' => true],
                    ]);
                }
            });

            $this->line(($online ? '● ' : '○ ')."{$cabinet->code}");
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function cabinetFrom(string $topic, TenantContext $context): ?Cabinet
    {
        $parsed = Topics::parse($topic);

        if ($parsed === null) {
            return null;
        }

        return $context->runWithBypass(
            fn (): ?Cabinet => Cabinet::query()->find($parsed['cabinet']),
        );
    }
}
