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
     * Durata della prenotazione del vano prima del pagamento (§7.1).
     * Scaduta: sessione `cancelled`, vano di nuovo `free`.
     */
    'reservation' => [
        'ttl' => (int) env('LOCKER_RESERVATION_TTL', 600),           // secondi
    ],

    /*
     * Oltre questa soglia senza heartbeat, il cabinet e' `offline` (§15) e non
     * accetta comandi.
     */
    'heartbeat' => [
        'timeout' => (int) env('LOCKER_HEARTBEAT_TIMEOUT', 90),      // secondi
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
