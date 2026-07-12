<?php

namespace App\Domain\Audit\Console;

use App\Domain\Audit\AuditHasher;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ricalcola la hash-chain dell'audit e segnala dove si rompe (piano §14).
 *
 * Una rottura significa che un record e' stato modificato, cancellato o riordinato. Il
 * database lo vieta gia' (`REVOKE UPDATE, DELETE` su `locker_app`): per riuscirci
 * servirebbero credenziali da amministratore del DB — il che rende la scoperta ancora
 * piu' interessante.
 *
 * Schedulato ogni ora (routes/console.php).
 */
final class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain';

    protected $description = 'Verifica l\'integrita\' della hash-chain dell\'audit log';

    public function handle(TenantContext $context): int
    {
        // La catena e' globale: va letta senza filtro tenant.
        $context->bypass();

        $previousHash = null;
        $checked = 0;
        /** @var list<int> $broken */
        $broken = [];

        DB::table('audit_logs')->orderBy('id')->each(
            function (object $row) use (&$previousHash, &$checked, &$broken): void {
                /** @var array<string, mixed> $record */
                $record = (array) $row;

                $expected = AuditHasher::hash($previousHash, $record);

                if ($expected !== $record['hash'] || $record['prev_hash'] !== $previousHash) {
                    $broken[] = (int) $record['id'];
                }

                $previousHash = is_string($record['hash']) ? $record['hash'] : null;
                $checked++;
            }
        );

        if ($broken !== []) {
            $this->error(sprintf(
                'CATENA ROTTA: %d record su %d non tornano. Primi id: %s',
                count($broken),
                $checked,
                implode(', ', array_slice($broken, 0, 10)),
            ));

            return self::FAILURE;
        }

        $this->info("Catena integra: {$checked} record verificati.");

        return self::SUCCESS;
    }
}
