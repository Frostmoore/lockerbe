<?php

namespace App\Domain\Session\Console;

use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Chiude le sessioni arrivate a fine serata (piano §7.4, §15).
 *
 * ⚠️ `expires_at` e' calcolato nel FUSO DEL LOCALE, mai sul giorno solare: un guardaroba
 * chiude alle 6 del mattino, e quella e' ancora "la serata di ieri". Chiudere a mezzanotte
 * significherebbe chiudere le sessioni nel momento di massimo affollamento.
 *
 * ⚠️ Il vano NON torna libero: resta occupato. Dentro c'e' la roba di qualcuno che non e'
 * tornato a riprendersela, e lo staff deve poter vedere quali armadietti sono rimasti pieni
 * per svuotarli a mano. Liberarli in automatico significherebbe riassegnare un vano col
 * cappotto di un altro ancora dentro.
 *
 * Schedulato ogni 5 minuti.
 */
final class CloseExpiredSessions extends Command
{
    protected $signature = 'sessions:close-expired';

    protected $description = 'Chiude le sessioni scadute (fine serata)';

    public function handle(TenantContext $context, SessionManager $sessions): int
    {
        $context->bypass();

        $closed = $sessions->closeExpiredSessions();

        if ($closed > 0) {
            $this->warn("{$closed} sessioni chiuse per fine serata: i vani sono ancora PIENI, vanno svuotati a mano.");
        }

        return self::SUCCESS;
    }
}
