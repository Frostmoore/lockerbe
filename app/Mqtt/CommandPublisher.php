<?php

namespace App\Mqtt;

use App\Domain\Mqtt\Topics;
use App\Models\Cabinet;
use App\Models\Command;
use Illuminate\Support\Str;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * Manda i comandi al device, via MQTT (piano §9).
 *
 * ⚠️ **`retain = false`. Non e' negoziabile.**
 *
 * Un messaggio "retained" viene riconsegnato dal broker **a ogni riconnessione** del device.
 * Un comando di apertura retained significa: il chiosco si riavvia alle 4 del mattino, il
 * broker gli riconsegna diligentemente l'`open` delle 23:00, e il vano si apre — pieno di
 * roba, davanti a nessuno. E' letteralmente il rischio #1 del sistema (§8), servito su un
 * piatto dalla configurazione di default.
 *
 * ⚠️ **Client-id proprio, diverso da quello del listener.** In MQTT due client con lo stesso
 * identificativo **si buttano fuori a vicenda**: se il publisher e il listener condividessero
 * l'id, ogni comando pubblicato scollegherebbe l'ascoltatore, e viceversa. Il sistema
 * sembrerebbe funzionare a intermittenza — che e' il modo peggiore di non funzionare.
 *
 * (E' anche il motivo per cui una **clonazione** di device si *vede*: due chioschi con lo
 * stesso id fanno sbattere la connessione. Vale come allarme.)
 */
class CommandPublisher
{
    public function publish(Command $command): bool
    {
        $cabinet = $command->cabinet()->first();

        if (! $cabinet instanceof Cabinet) {
            return false;
        }

        $payload = json_encode([
            'v' => 1,
            'id' => $command->id,
            'type' => $command->type,
            'reason' => $command->reason,
            'locker' => $command->payload['locker'] ?? null,

            // ⚠️ L'uuid del vano entra nel payload perche' **entra nella firma**: senza, il
            // device dovrebbe indovinarlo da una mappa locale, e una mappa che diverge e' una
            // firma che non torna piu' — cioe' un armadio che rifiuta tutti i comandi.
            'locker_id' => $command->locker_id,

            'issued_at' => $command->issued_at->utc()->toIso8601String(),

            // ⚠️ La scadenza viaggia CON il comando: il device la controlla da solo e scarta
            // cio' che e' arrivato troppo tardi. Se ci fidassimo solo del server, basterebbe un
            // ritardo di rete per aprire un vano fuori tempo.
            'expires_at' => $command->expires_at->utc()->toIso8601String(),

            'sig' => $command->signature,
        ], JSON_THROW_ON_ERROR);

        try {
            $client = $this->connect();

            // (topic, message, qos, retain) — ⚠️ `retain = false`: vedi il commento in testa.
            $client->publish(Topics::command($cabinet), $payload, 1, false);

            $client->disconnect();
        } catch (Throwable $e) {
            // Il broker non risponde. Il comando resta `pending` e scadra' da solo: meglio un
            // comando mai partito che un comando partito quando non doveva.
            report($e);

            return false;
        }

        $command->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        return true;
    }

    private function connect(): MqttClient
    {
        $client = new MqttClient(
            (string) config('locker.mqtt.host'),
            (int) config('locker.mqtt.port'),
            // Id unico per pubblicazione: vedi il commento in testa alla classe.
            'locker-pub-'.Str::random(10),
        );

        $settings = (new ConnectionSettings)
            ->setUsername((string) config('locker.mqtt.server_username'))
            ->setPassword((string) config('locker.mqtt.server_password'))
            ->setConnectTimeout(3)
            ->setSocketTimeout(3);

        $client->connect($settings, true);

        return $client;
    }
}
