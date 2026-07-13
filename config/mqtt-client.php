<?php

/*
 * Client MQTT (php-mqtt/laravel-client).
 *
 * ⚠️ Il server e' un client come gli altri: si autentica e ha permessi limitati. **Non e'
 * superuser del broker** — un superuser scavalcherebbe tutte le ACL, cioe' l'intero confine
 * tra clienti sul canale realtime.
 */

use PhpMqtt\Client\Repositories\MemoryRepository;

return [
    'default_connection' => 'default',

    'connections' => [
        'default' => [
            'host' => env('MQTT_HOST', '127.0.0.1'),
            'port' => (int) env('MQTT_PORT', 1883),
            'protocol' => '3.1.1',
            // ⚠️ Il LISTENER ha un id proprio: due client MQTT con lo stesso identificativo si
            // buttano fuori a vicenda. Il publisher usa il suo (vedi CommandPublisher).
            'client_id' => env('MQTT_CLIENT_ID', 'locker-server').'-listener',
            // ⚠️ Il SERVER usa una sessione persistente per poter riconnettersi da solo: e'
            // un consumatore, non un attuatore. Il DEVICE, invece, usa clean session — se il
            // broker gli accumulasse i comandi mentre e' spento, glieli riconsegnerebbe tutti
            // alla riaccensione, e gli armadietti si aprirebbero a raffica alle 4 del mattino.
            'use_clean_session' => false,
            'enable_logging' => false,
            'log_channel' => null,
            'repository' => MemoryRepository::class,

            'connection_settings' => [
                'auth' => [
                    'username' => env('MQTT_SERVER_USERNAME', 'locker-server'),
                    'password' => env('MQTT_SERVER_PASSWORD', 'locker-server-secret'),
                ],
                'connect_timeout' => 5,
                'socket_timeout' => 5,
                'resend_timeout' => 5,
                'keep_alive_interval' => 30,
                'auto_reconnect' => [
                    'enabled' => true,
                    'max_reconnect_attempts' => 3,
                    'delay_between_reconnect_attempts' => 1,
                ],
            ],
        ],
    ],
];
