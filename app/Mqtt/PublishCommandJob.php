<?php

namespace App\Mqtt;

use App\Domain\Tenancy\TenantContext;
use App\Models\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pubblica un comando sul broker, **fuori dalla richiesta HTTP** (piano §15).
 *
 * ⚠️ Perche' un job e non una pubblicazione inline — due motivi, e il secondo e' un deadlock
 * vero, non teorico:
 *
 *  1. **Latenza e fragilita'.** Il broker puo' essere lento o irraggiungibile: chi ha premuto
 *     "apri" non deve aspettare che si risolva. La risposta e' gia' un **202** ("preso in
 *     carico"), non un "aperto": e' coerente che la consegna avvenga dopo.
 *
 *  2. ⚠️ **DEADLOCK.** Il nostro broker, per autenticare chi si connette, **chiama il nostro
 *     server** (le ACL le decidiamo noi, §3.3). Se il server pubblicasse dentro la richiesta
 *     HTTP, si troverebbe ad aspettare il broker, che aspetta il server: con un solo worker si
 *     blocca tutto. Il job gira in un **processo separato**, e il cerchio si spezza.
 *
 * ⚠️ Il contesto tenant NON sopravvive alla coda: il job non ha una request. Va reimpostato a
 * mano, o le policy RLS — che sono fail-closed — non farebbero vedere al job nemmeno il proprio
 * comando. (Era il debito lasciato aperto da F1.)
 */
final class PublishCommandJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $commandId,
        private readonly string $tenantId,
    ) {}

    public function handle(TenantContext $context, CommandPublisher $publisher): void
    {
        $context->runForTenant($this->tenantId, function () use ($publisher): void {
            $command = Command::query()->find($this->commandId);

            if (! $command instanceof Command) {
                return;
            }

            // ⚠️ Se nel frattempo il comando e' scaduto, NON si pubblica. Il TTL vale anche qui:
            // un comando che ha aspettato troppo in coda e' esattamente il comando che non deve
            // partire.
            if (! $command->isDeliverable()) {
                return;
            }

            $publisher->publish($command);
        });
    }
}
