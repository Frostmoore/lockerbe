<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler (piano §15).
 *
 * Qui, per ora, c'e' solo la verifica dell'audit: le altre voci (scadenza dei comandi,
 * prenotazioni scadute, cabinet offline, riconciliazione) arrivano con F3 e F4, insieme
 * alle tabelle su cui operano.
 */

// Se la catena di hash dell'audit si rompe, qualcuno ha riscritto la storia — e noi
// vogliamo saperlo entro un'ora, non il giorno che serve la prova in tribunale.
Schedule::command('audit:verify-chain')->hourly();

// ⚠️ Se questo non gira, gli armadi restano `online` per sempre — e da F4 un armadio
// creduto online accetta comandi di apertura che verranno consegnati chissa' quando.
// E' la difesa contro il rischio #1 del sistema (§8), e vive qui.
Schedule::command('cabinets:mark-offline')->everyMinute();

// ⚠️ Senza questo, un vano prenotato e mai pagato resta bloccato per sempre: un armadietto
// vuoto che il sistema crede occupato.
Schedule::command('sessions:cancel-expired-reservations')->everyMinute();

// Fine serata (nel fuso del locale, mai sul giorno solare — §7.4).
Schedule::command('sessions:close-expired')->everyFiveMinutes();
