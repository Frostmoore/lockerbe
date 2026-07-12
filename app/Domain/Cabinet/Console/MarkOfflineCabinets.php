<?php

namespace App\Domain\Cabinet\Console;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Porta a `offline` gli armadi che hanno smesso di dare segni di vita (piano §15).
 *
 * Perche' e' importante: da F4, un comando di apertura verso un armadio offline **non si
 * accoda** — l'API risponde 409. E' la difesa contro il rischio #1 del sistema (§8): un
 * `open` accodato mentre l'armadio e' irraggiungibile e consegnato tre ore dopo apre un
 * vano pieno di roba alle 4 del mattino.
 *
 * Se questo comando non gira, gli armadi restano `online` per sempre e quella difesa non
 * scatta mai. Schedulato **ogni minuto** (routes/console.php).
 *
 * `maintenance` non viene toccato: e' uno stato deciso da un umano, non dall'heartbeat.
 */
final class MarkOfflineCabinets extends Command
{
    protected $signature = 'cabinets:mark-offline';

    protected $description = 'Marca offline gli armadi il cui heartbeat e\' scaduto';

    public function handle(TenantContext $context): int
    {
        // Comando di sistema: opera su tutti i tenant.
        $context->bypass();

        $timeout = (int) config('locker.heartbeat.timeout');
        $threshold = now()->subSeconds($timeout);

        $affected = DB::table('cabinets')
            ->where('status', 'online')
            ->where(function ($query) use ($threshold): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $threshold);
            })
            ->update(['status' => 'offline', 'updated_at' => now()]);

        if ($affected > 0) {
            $this->warn("{$affected} armadi passati a offline (heartbeat oltre {$timeout}s).");
        }

        return self::SUCCESS;
    }
}
