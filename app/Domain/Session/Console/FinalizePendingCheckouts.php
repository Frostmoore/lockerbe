<?php

namespace App\Domain\Session\Console;

use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Chiude le riconsegne rimaste in sospeso oltre la finestra di cortesia.
 *
 * ⚠️ E' un RIPIEGO, e va detto chiaramente. La conferma giusta che un vano e' stato svuotato
 * e' lo **sportello richiuso**, riportato dal device — ma non sappiamo ancora se la scheda
 * serrature VF203_V12 sappia leggerlo (**D5**, il datasheet non e' arrivato).
 *
 * Finche' D5 e' aperta, senza questo timer nessun vano tornerebbe **mai** libero dopo una
 * riconsegna: il cliente dichiara "ho finito", prende il cappotto, se ne va, e il vano
 * resterebbe suo fino a fine serata. Il locale perderebbe tutta la rotazione.
 *
 * Il rischio che ci prendiamo, in cambio: se il cliente lascia lo sportello aperto, il vano
 * risulta libero ed e' fisicamente spalancato. Il prossimo cliente se ne accorge subito — un
 * fastidio, non un danno. E' il male minore rispetto a un vano che non si libera mai.
 *
 * Quando D5 si sbloccera', la conferma arrivera' dal device e questo comando restera' solo
 * come rete di sicurezza per i device muti.
 *
 * Schedulato ogni minuto.
 */
final class FinalizePendingCheckouts extends Command
{
    protected $signature = 'sessions:finalize-checkouts';

    protected $description = 'Chiude le riconsegne oltre la finestra di cortesia e libera i vani';

    public function handle(TenantContext $context, SessionManager $sessions): int
    {
        $context->bypass();

        $chiuse = $sessions->finalizePendingCheckouts();

        if ($chiuse > 0) {
            $this->info("{$chiuse} riconsegne chiuse allo scadere della finestra: vani liberati.");
        }

        return self::SUCCESS;
    }
}
