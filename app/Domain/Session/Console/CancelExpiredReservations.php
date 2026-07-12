<?php

namespace App\Domain\Session\Console;

use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Annulla le prenotazioni non pagate entro il tempo concesso (piano §15).
 *
 * ⚠️ Senza questo, un vano prenotato e mai pagato resterebbe bloccato per sempre: un
 * armadietto vuoto che il sistema crede occupato, sottratto ai clienti per sempre. In un
 * guardaroba con 24 vani, bastano poche serate per riempirlo di fantasmi.
 *
 * Schedulato ogni minuto.
 */
final class CancelExpiredReservations extends Command
{
    protected $signature = 'sessions:cancel-expired-reservations';

    protected $description = 'Annulla le prenotazioni scadute e libera i vani';

    public function handle(TenantContext $context, SessionManager $sessions): int
    {
        // Comando di sistema: attraversa tutti i locali.
        $context->bypass();

        $cancelled = $sessions->cancelExpiredReservations();

        if ($cancelled > 0) {
            $this->info("{$cancelled} prenotazioni scadute annullate, vani liberati.");
        }

        return self::SUCCESS;
    }
}
