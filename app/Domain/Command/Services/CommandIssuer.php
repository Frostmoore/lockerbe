<?php

namespace App\Domain\Command\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Contracts\CommandDispatcher;
use App\Domain\Command\Exceptions\DeviceOfflineException;
use App\Events\CommandAcked;
use App\Events\CommandIssued;
use App\Models\Command;
use App\Models\Locker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emette i comandi verso i device. **Sostituisce il finto dispatcher di F3.**
 *
 * ═══ LE TRE DIFESE, E PERCHE' ESISTONO ═══
 *
 * Il piano lo chiama **il rischio #1 del sistema** (§8), e non e' un'esagerazione: un `open`
 * accodato mentre l'armadio e' offline e consegnato tre ore dopo **apre un vano pieno di roba
 * alle 4 del mattino**, davanti a nessuno. Con MQTT questo accade **di default** — retained,
 * QoS con sessione persistente. Bisogna impedirlo apposta.
 *
 *  1. ⚠️ **ARMADIO OFFLINE ⇒ NIENTE COMANDO.** Non si accoda una promessa di apertura: si
 *     risponde 409 e non si crea nulla. La tentazione ("appena torna online, si apre") e'
 *     esattamente cio' che produce l'armadietto che si apre da solo nel cuore della notte.
 *
 *  2. ⚠️ **OGNI COMANDO SCADE** (`expires_at`, default 30s). La scadenza viaggia **dentro il
 *     payload**, e il device rifiuta i comandi scaduti: il controllo esiste due volte, di qua
 *     e di la'. Un comando che sopravvive al proprio senso e' un comando pericoloso.
 *
 *  3. ⚠️ **IDEMPOTENZA**: la chiave la genera il client e **diventa la PK** del comando. Un
 *     retry di rete restituisce lo stesso comando, non ne crea un secondo. Senza, ogni
 *     singhiozzo della rete e' un'apertura in piu'.
 *
 * Piu' la **firma HMAC** per-device: chi riuscisse a pubblicare sul broker senza la chiave
 * produrrebbe messaggi che il device scarta.
 *
 * ⚠️ In F4 il comando nasce `pending` e **non parte davvero**: MQTT arriva in F5. Il
 * `MockDeviceSimulator` (i bottoni) fa da device: manda l'heartbeat e risponde con l'ack.
 */
final class CommandIssuer implements CommandDispatcher
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CommandSigner $signer,
    ) {}

    /**
     * @param  'store'|'reopen'|'checkout'|'admin'|'maintenance'  $reason
     *
     * @throws DeviceOfflineException
     */
    public function issueOpen(Locker $locker, string $reason, ?string $idempotencyKey = null): string
    {
        $cabinet = $locker->cabinet()->firstOrFail();

        /*
         * ⚠️ DIFESA 1 — L'armadio deve rispondere ORA.
         *
         * Non "dovrebbe rispondere", non "risponderà": deve aver dato segno di vita entro
         * LOCKER_HEARTBEAT_TIMEOUT. Se non lo ha fatto, non si crea nessun comando — nemmeno
         * uno "in attesa". Un comando che esiste e' un comando che prima o poi puo' partire.
         */
        if (! $cabinet->isOnline()) {
            $this->audit->log('command.rejected', [
                'cabinet_id' => $cabinet->id,
                'locker_id' => $locker->id,
                'result' => 'fail',
                'error_code' => 'device_offline',
                'context' => ['reason' => $reason, 'last_seen_at' => $cabinet->last_seen_at?->toIso8601String()],
            ]);

            throw new DeviceOfflineException($cabinet);
        }

        /*
         * ⚠️ DIFESA 3 — Idempotenza.
         *
         * La chiave e' la PK. Se il client riprova con la stessa chiave (retry di rete, doppio
         * click, webhook consegnato due volte) ritrova **lo stesso comando**, e il vano non si
         * apre una seconda volta.
         */
        $commandId = $idempotencyKey ?? (string) Str::uuid7();

        $esistente = Command::query()->find($commandId);

        if ($esistente instanceof Command) {
            return $esistente->id;
        }

        $ttl = (int) config('locker.command.ttl_open');

        return DB::transaction(function () use ($locker, $cabinet, $reason, $commandId, $ttl): string {
            $attore = auth()->user();

            $command = new Command([
                'id' => $commandId,
                'cabinet_id' => $cabinet->id,
                'locker_id' => $locker->id,
                'session_id' => $locker->current_session_id,
                'type' => 'open',
                'reason' => $reason,
                'status' => 'pending',
                'issued_by_type' => $attore instanceof User ? 'user' : 'system',
                'issued_by_id' => $attore instanceof User ? $attore->id : null,
                'issued_at' => now(),

                // ⚠️ DIFESA 2 — La scadenza. NOT NULL a livello di schema, e non e'
                // negoziabile: e' cio' che impedisce a un comando di sopravvivere al proprio
                // senso.
                'expires_at' => now()->addSeconds($ttl),

                'payload' => [
                    'v' => 1,
                    'locker' => $locker->physicalAddress(),
                ],
            ]);
            $command->save();

            // La firma copre anche `expires_at`: un comando vecchio non si puo' rigiocare
            // cambiandogli la data, perche' la firma non tornerebbe.
            $device = $cabinet->device()->first();

            if ($device !== null && $device->signing_secret !== null) {
                $command->forceFill([
                    'signature' => $this->signer->sign($command, $device),
                ])->save();
            }

            $this->audit->log('command.issued', [
                'cabinet_id' => $cabinet->id,
                'locker_id' => $locker->id,
                'session_id' => $command->session_id,
                'command_id' => $command->id,
                'context' => [
                    'type' => 'open',
                    'reason' => $reason,
                    'expires_at' => $command->expires_at->toIso8601String(),
                    'ttl_seconds' => $ttl,
                ],
            ]);

            CommandIssued::dispatch($command);

            return $command->id;
        });
    }

    /**
     * Il device ha risposto.
     *
     * ⚠️ **Un ack su un comando scaduto non lo resuscita.** Se il device risponde dopo la
     * scadenza vuol dire che il comando e' rimasto in giro piu' del dovuto: si registra il
     * fatto, ma il comando resta `expired`. Accettarlo significherebbe rimettere in gioco
     * proprio cio' che il TTL serviva a evitare.
     *
     * @param  array<string, mixed>  $result
     */
    public function ack(Command $command, bool $ok, array $result = []): Command
    {
        if ($command->isExpired()) {
            $this->audit->log('command.ack_too_late', [
                'cabinet_id' => $command->cabinet_id,
                'locker_id' => $command->locker_id,
                'command_id' => $command->id,
                'result' => 'fail',
                'error_code' => 'command_expired',
                'context' => ['ack_ricevuto_dopo_la_scadenza' => true],
            ]);

            return $command;
        }

        $command->forceFill([
            'status' => $ok ? 'acked' : 'failed',
            'acked_at' => now(),
            'attempts' => $command->attempts + 1,
            'result' => $result,
        ])->save();

        $this->audit->log($ok ? 'command.acked' : 'command.failed', [
            'cabinet_id' => $command->cabinet_id,
            'locker_id' => $command->locker_id,
            'session_id' => $command->session_id,
            'command_id' => $command->id,
            'result' => $ok ? 'ok' : 'fail',
            'context' => $result,
        ]);

        CommandAcked::dispatch($command);

        return $command;
    }

    /**
     * Porta a `expired` i comandi che hanno superato il TTL senza risposta.
     *
     * ⚠️ Non e' pulizia cosmetica: e' la garanzia che un comando **non consegnato** non resti
     * indefinitamente consegnabile. Schedulato ogni minuto.
     *
     * @return int quanti comandi sono scaduti
     */
    public function expireStale(): int
    {
        $scaduti = Command::query()
            ->whereIn('status', ['pending', 'sent'])
            ->where('expires_at', '<', now())
            ->get();

        foreach ($scaduti as $command) {
            $command->forceFill(['status' => 'expired'])->save();

            $this->audit->log('command.expired', [
                'cabinet_id' => $command->cabinet_id,
                'locker_id' => $command->locker_id,
                'session_id' => $command->session_id,
                'command_id' => $command->id,
                'result' => 'fail',
                'error_code' => 'command_expired',
            ]);
        }

        return $scaduti->count();
    }
}
