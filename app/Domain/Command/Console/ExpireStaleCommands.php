<?php

namespace App\Domain\Command\Console;

use App\Domain\Command\Services\CommandIssuer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command as ArtisanCommand;

/**
 * Porta a `expired` i comandi che hanno superato il TTL senza risposta (piano §8.7).
 *
 * ⚠️ Non e' pulizia cosmetica. E' la garanzia che un comando **non consegnato** non resti
 * indefinitamente consegnabile: senza questo, un `open` emesso e mai partito resterebbe
 * `pending` per sempre — e il giorno che qualcuno (o qualche retry) lo tirasse fuori, aprirebbe
 * un vano pieno di roba davanti a nessuno.
 *
 * Schedulato ogni minuto.
 */
final class ExpireStaleCommands extends ArtisanCommand
{
    protected $signature = 'commands:expire-stale';

    protected $description = 'Porta a `expired` i comandi oltre il TTL';

    public function handle(TenantContext $context, CommandIssuer $commands): int
    {
        $context->bypass();

        $scaduti = $commands->expireStale();

        if ($scaduti > 0) {
            $this->warn("{$scaduti} comandi scaduti senza risposta del device.");
        }

        return self::SUCCESS;
    }
}
