<?php

/*
 * Configurazione di dominio (piano §16).
 *
 * env() si chiama SOLO qui dentro. Sparsa nel codice smetterebbe di funzionare
 * appena si esegue `php artisan config:cache`, e lo scopriremmo in produzione.
 */

return [

    /*
     * Driver di pagamento e identita' (piano §11.4, §12).
     *
     * Restano 'mock' finche' D1 (Nexi) e D2 (NFC vs web) non sono sbloccate col
     * cliente. Passare al reale = implementare i due contratti e cambiare queste
     * due righe: nient'altro nel sistema deve muoversi. Se dovesse muoversi,
     * vorrebbe dire che i contratti erano sbagliati.
     */
    'payment' => [
        'driver' => env('LOCKER_PAYMENT_DRIVER', 'mock'),
    ],

    'identity' => [
        'driver' => env('LOCKER_IDENTITY_DRIVER', 'mock'),
    ],

    /*
     * Abilita i bottoni mock (§12) e l'emulatore device (F5).
     *
     * Doppiamente protetto: oltre a questo flag, le rotte sono registrate solo se
     * APP_ENV != production. Il device fisico non e' disponibile, quindi l'emulatore
     * e' oggi l'unico modo di vedere girare il sistema: e' un attrezzo di sviluppo,
     * e in production non deve esistere.
     */
    'mock_panel' => (bool) env('LOCKER_MOCK_PANEL', false),

    /*
     * La password dell'emulatore — il TERZO lucchetto (§ ProtectEmulator).
     *
     * ⚠️ Il doppio cancello qui sopra non basta: promette "non esiste in produzione", ma lo
     * promette guardando `APP_ENV`, e il server vero — su un dominio pubblico — gira
     * `APP_ENV=staging` col flag acceso. La pagina era aperta a chiunque, e da li' si leggono
     * le credenziali MQTT dei chioschi.
     *
     * Hash **bcrypt**, non la password in chiaro. Puo' arrivare con qualunque prefisso
     * (`$2a$`, `$2b$`, `$2y$`): ci pensa `App\Support\Bcrypt` a normalizzarlo — vedi la
     * trappola 30, un `$2a$` passato a `Hash::check()` non risponde `false`, **esplode**.
     *
     * ⚠️ Vuota = **emulatore chiuso** (fail-closed). Una configurazione dimenticata non deve
     * mai voler dire "entra pure".
     */
    'emulator' => [
        'password_hash' => (string) env('LOCKER_EMULATOR_PASSWORD_HASH', ''),
    ],

    /*
     * TTL dei comandi (piano §8) — il rischio #1 del sistema.
     *
     * Un `open` accodato mentre l'armadio e' offline e consegnato tre ore dopo apre
     * un vano pieno di roba alle 4 del mattino. Con MQTT questo accade DI DEFAULT.
     * Percio' ogni comando scade, il device rifiuta quelli scaduti, e un armadio
     * offline riceve 409 invece di una promessa di apertura.
     */
    'command' => [
        'ttl_open' => (int) env('LOCKER_COMMAND_TTL_OPEN', 30),      // secondi
    ],

    /*
     * Il prezzo di un vano quando NESSUNO ha deciso: né l'armadio, né il locale.
     *
     * ⚠️ È l'ultimo anello di `SessionManager::tariffFor()`: armadio → locale → questo.
     * Ed è il **default di piattaforma**, non "il prezzo": un locale che non lo tocca sta
     * vendendo a 5 €, e deve essere una scelta, non una dimenticanza.
     *
     * ⚠️ Sta qui e non copiato in tre punti del codice, com'era: `SessionManager`,
     * `CabinetResource` e la tabella degli armadi avevano ciascuno il proprio `?? 500`.
     * Cambiarne uno e non gli altri avrebbe prodotto un pannello che mostra un prezzo e una
     * cassa che ne incassa un altro.
     */
    'tariff' => [
        'default' => (int) env('LOCKER_TARIFF_DEFAULT', 500),        // centesimi
    ],

    /*
     * Durata della prenotazione del vano prima del pagamento (§7.1).
     * Scaduta: sessione `cancelled`, vano di nuovo `free`.
     */
    'reservation' => [
        'ttl' => (int) env('LOCKER_RESERVATION_TTL', 600),           // secondi
    ],

    /*
     * Finestra di cortesia della RICONSEGNA.
     *
     * Il cliente ha dichiarato "ho finito", il vano si e' aperto, ma il sistema non puo'
     * sapere se l'ha davvero svuotato. La conferma giusta e' lo sportello richiuso — ma
     * dipende da D5 (la scheda serrature sa leggerlo?), ancora aperta. Finche' non lo
     * sappiamo, il vano torna libero allo scadere di questa finestra.
     *
     * ⚠️ E' un ripiego, non una soluzione: senza sensore, un cliente che lascia lo sportello
     * aperto produce un vano dichiarato libero e fisicamente spalancato. Quando D5 si
     * sbloccera', la conferma arrivera' dal device e questo timer diventera' solo una rete
     * di sicurezza.
     *
     * Dentro la finestra, un nuovo tap della carta ANNULLA la riconsegna.
     */
    'checkout' => [
        'grace' => (int) env('LOCKER_CHECKOUT_GRACE', 120),          // secondi
    ],

    /*
     * Oltre questa soglia senza heartbeat, il cabinet e' `offline` (§15) e non
     * accetta comandi.
     */
    'heartbeat' => [
        'timeout' => (int) env('LOCKER_HEARTBEAT_TIMEOUT', 90),      // secondi
    ],

    /*
     * MQTT (piano §9) — F5.
     *
     * ⚠️ Il server e' un client come gli altri: ha un'identita' e dei permessi, e **non e'
     * superuser** del broker. Un superuser MQTT scavalcherebbe tutte le ACL, cioe' l'intero
     * confine tra clienti sul canale realtime.
     */
    'mqtt' => [
        'host' => env('MQTT_HOST', '127.0.0.1'),
        'port' => (int) env('MQTT_PORT', 1883),
        'client_id' => env('MQTT_CLIENT_ID', 'locker-server'),
        'server_username' => env('MQTT_SERVER_USERNAME', 'locker-server'),
        'server_password' => env('MQTT_SERVER_PASSWORD', 'locker-server-secret'),

        // L'indirizzo che l'EMULATORE (che gira nel browser) usa per raggiungere il broker.
        'ws_url' => env('MQTT_WS_URL', 'ws://127.0.0.1:9001'),
    ],

    /*
     * OTA (piano §13) — usato da F7.
     *
     * Un .dpk difettoso pushato a tutti = tutti gli armadi di tutti i clienti
     * bricked insieme, e da remoto non li recuperi. Da qui il canary a 1 armadio.
     */
    'ota' => [
        'canary_size' => (int) env('OTA_CANARY_SIZE', 1),
        'health_timeout' => (int) env('OTA_HEALTH_TIMEOUT', 600),    // secondi
    ],

];
