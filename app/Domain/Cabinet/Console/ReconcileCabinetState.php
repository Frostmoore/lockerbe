<?php

namespace App\Domain\Cabinet\Console;

use App\Domain\Mqtt\Topics;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Command;
use Illuminate\Console\Command as ArtisanCommand;
use Illuminate\Support\Str;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * Chiede agli armadi vivi di raccontare come stanno davvero (piano §15).
 *
 * ⚠️ Il server e il device possono **divergere**: un comando partito e mai eseguito, un ack
 * perso, un riavvio del chiosco a meta' operazione. Il server crede che il vano 7 sia aperto;
 * il vano 7 e' chiuso. O peggio: il server lo crede libero e dentro c'e' un cappotto.
 *
 * Un sistema che apre serrature non puo' permettersi di credere a se stesso senza mai
 * verificare. Questo comando manda un `sync`: il device risponde con lo stato reale dei vani, e
 * le divergenze finiscono nell'audit.
 *
 * Schedulato ogni 10 minuti.
 */
final class ReconcileCabinetState extends ArtisanCommand
{
    protected $signature = 'cabinets:reconcile';

    protected $description = 'Chiede agli armadi online lo stato reale dei vani';

    public function handle(TenantContext $context): int
    {
        $context->bypass();

        $armadi = Cabinet::query()
            ->whereNot('status', 'maintenance')
            ->whereNotNull('last_seen_at')
            ->get()
            ->filter(fn (Cabinet $c) => $c->isOnline());

        foreach ($armadi as $cabinet) {
            $this->sync($cabinet, $context);
        }

        $this->info("{$armadi->count()} armadi interrogati.");

        return self::SUCCESS;
    }

    private function sync(Cabinet $cabinet, TenantContext $context): void
    {
        $context->runForTenant($cabinet->tenant_id, function () use ($cabinet): void {
            $command = new Command([
                'id' => (string) Str::uuid7(),
                'cabinet_id' => $cabinet->id,
                'type' => 'sync',
                'reason' => 'maintenance',
                'status' => 'pending',
                'issued_by_type' => 'system',
                'issued_at' => now(),

                // ⚠️ Anche il `sync` scade: nessun comando, di nessun tipo, sopravvive al
                // proprio senso.
                'expires_at' => now()->addSeconds((int) config('locker.command.ttl_open')),
                'payload' => ['v' => 1],
            ]);
            $command->save();

            try {
                $client = new MqttClient(
                    (string) config('locker.mqtt.host'),
                    (int) config('locker.mqtt.port'),
                    'locker-sync-'.Str::random(8),
                );

                $client->connect(
                    (new ConnectionSettings)
                        ->setUsername((string) config('locker.mqtt.server_username'))
                        ->setPassword((string) config('locker.mqtt.server_password')),
                    true,
                );

                $client->publish(Topics::command($cabinet), json_encode([
                    'v' => 1,
                    'id' => $command->id,
                    'type' => 'sync',
                    'expires_at' => $command->expires_at->utc()->toIso8601String(),
                ], JSON_THROW_ON_ERROR), 1, false);

                $client->disconnect();

                $command->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
