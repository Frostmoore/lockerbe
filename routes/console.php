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
