<?php

namespace App\Domain\Command\Dispatchers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Contracts\CommandDispatcher;
use App\Models\Locker;
use Illuminate\Support\Str;

/**
 * Il dispatcher di F3: registra l'intenzione di aprire, ma **non apre niente**.
 *
 * Non esiste ancora una tabella `commands`, non esiste MQTT, e soprattutto non esistono
 * ancora le difese: TTL, idempotenza, rifiuto verso gli armadi offline. Questo dispatcher
 * scrive nell'audit che un'apertura *sarebbe stata* emessa, e restituisce un id.
 *
 * ⚠️ **Perche' non aprire subito, visto che si potrebbe.** Perche' un `open` senza TTL
 * accodato verso un armadio irraggiungibile viene consegnato ore dopo — e apre un vano
 * pieno di roba alle 4 del mattino (piano §8, il rischio #1 del sistema). Prima si
 * costruiscono le difese (F4), poi si collega l'arma. Nel frattempo il flusso e' completo
 * e verificabile in ogni suo passaggio: l'unica cosa che manca e' lo scatto della serratura.
 *
 * In F4 questa classe viene sostituita da quella vera. Il binding e' in AppServiceProvider,
 * `SessionManager` non se ne accorge nemmeno.
 */
final class RecordingCommandDispatcher implements CommandDispatcher
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function issueOpen(Locker $locker, string $reason, ?string $idempotencyKey = null): string
    {
        $commandId = $idempotencyKey ?? (string) Str::uuid7();

        $this->audit->log('command.issued', [
            'cabinet_id' => $locker->cabinet_id,
            'locker_id' => $locker->id,
            'session_id' => $locker->current_session_id,
            'command_id' => $commandId,
            'context' => [
                'type' => 'open',
                'reason' => $reason,
                'dispatcher' => 'recording',   // ⚠️ non e' partito niente: F4.
                'locker' => $locker->physicalAddress(),
            ],
        ]);

        return $commandId;
    }
}
