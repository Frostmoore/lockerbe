<?php

namespace App\Mqtt;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Services\CommandIssuer;
use App\Domain\Identity\Contracts\IdentityProvider;
use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Identity;
use App\Models\Session;

/**
 * Cio' che il device racconta al server (piano §9).
 *
 * Eventi: `cmd.ack` · `heartbeat` · `locker.opened` · `locker.closed` · `locker.error` ·
 * `identity.presented`.
 *
 * ⚠️ **Ogni evento viene gestito DENTRO il tenant del suo armadio.** Un handler che girasse in
 * bypass potrebbe, per un id sbagliato in un payload, toccare i dati di un altro locale.
 */
final class DeviceEventHandler
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommandIssuer $commands,
        private readonly SessionManager $sessions,
        private readonly IdentityProvider $identities,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Cabinet $cabinet, array $payload): void
    {
        $tipo = (string) ($payload['type'] ?? '');

        $this->context->runForTenant($cabinet->tenant_id, function () use ($cabinet, $tipo, $payload): void {
            match ($tipo) {
                'heartbeat' => $this->heartbeat($cabinet, $payload),
                'cmd.ack' => $this->commandAck($payload),
                'locker.opened', 'locker.closed', 'locker.error' => $this->lockerEvent($cabinet, $tipo, $payload),
                'identity.presented' => $this->identityPresented($cabinet, $payload),
                default => $this->audit->log('device.event_unknown', [
                    'cabinet_id' => $cabinet->id,
                    'actor_type' => 'device',
                    'result' => 'fail',
                    'context' => ['type' => $tipo],
                ]),
            };
        });
    }

    /**
     * 💓 Il device e' vivo.
     *
     * ⚠️ E' cio' che rende l'armadio **raggiungibile**: senza heartbeat, ogni apertura
     * risponde 409 e nessun comando viene accodato (§8.4). E' la difesa che fa il suo mestiere.
     *
     * @param  array<string, mixed>  $payload
     */
    private function heartbeat(Cabinet $cabinet, array $payload): void
    {
        $cabinet->forceFill([
            'status' => $cabinet->status === 'maintenance' ? 'maintenance' : 'online',
            'last_seen_at' => now(),
            'firmware_version' => $payload['fw'] ?? $cabinet->firmware_version,
        ])->save();

        $cabinet->device()->first()?->forceFill([
            'status' => 'online',
            'last_seen_at' => now(),
            'firmware_version' => $payload['fw'] ?? null,
            'ip_address' => $payload['ip'] ?? null,
        ])->save();
    }

    /**
     * 📟 Il device ha eseguito (o rifiutato) un comando.
     *
     * ⚠️ Se il device dice di aver **rifiutato** un comando scaduto o mal firmato, non e' un
     * errore: e' la seconda meta' della difesa che funziona. Va registrato come tale.
     *
     * @param  array<string, mixed>  $payload
     */
    private function commandAck(array $payload): void
    {
        $command = Command::query()->find((string) ($payload['command_id'] ?? ''));

        if (! $command instanceof Command) {
            return;
        }

        $ok = (bool) ($payload['ok'] ?? false);

        $this->commands->ack($command, $ok, [
            'error' => $payload['error'] ?? null,
            'da_device' => true,
        ]);
    }

    /**
     * 🚪 Lo sportello si e' aperto, chiuso, o ha dato errore.
     *
     * ⚠️ **`locker.closed` e' la conferma vera della riconsegna** (§7.1-bis): finche' non
     * arriva, il vano riconsegnato resta `checkout` e non viene riassegnato. E' il motivo per
     * cui D5 (la scheda sa dire se lo sportello e' chiuso?) conta cosi' tanto: senza, si va a
     * timer, che e' un ripiego.
     *
     * @param  array<string, mixed>  $payload
     */
    private function lockerEvent(Cabinet $cabinet, string $tipo, array $payload): void
    {
        $numero = (int) ($payload['locker'] ?? 0);

        $locker = $cabinet->lockers()->where('number', $numero)->first();

        if ($locker === null) {
            return;
        }

        $this->audit->log($tipo, [
            'cabinet_id' => $cabinet->id,
            'locker_id' => $locker->id,
            'actor_type' => 'device',
            'result' => $tipo === 'locker.error' ? 'fail' : 'ok',
            'context' => $payload,
        ]);

        if ($tipo !== 'locker.closed' || $locker->status !== 'checkout') {
            return;
        }

        // ⚠️ Lo sportello del vano in riconsegna e' stato richiuso: **e' la conferma che il
        // cliente ha svuotato**. Il vano torna libero — e stavolta non per un timer, ma perche'
        // il mondo fisico ce l'ha detto.
        $session = Session::query()
            ->where('locker_id', $locker->id)
            ->where('status', 'active')
            ->whereNotNull('checkout_pending_at')
            ->first();

        if ($session instanceof Session) {
            $this->sessions->confirmCheckout($session, 'device');
        }
    }

    /**
     * 🪪 Il cliente ha presentato la carta.
     *
     * ⚠️ **Stessa code-path del bottone mock.** Cambia solo chi produce il token: la' un
     * bottone, qui il lettore NFC del FCV5003. Se funziona col mock e non con la carta vera, il
     * bug e' nel lettore — non nel dominio.
     *
     * @param  array<string, mixed>  $payload
     */
    private function identityPresented(Cabinet $cabinet, array $payload): void
    {
        $token = (string) ($payload['token'] ?? '');
        $intent = (string) ($payload['intent'] ?? 'reopen');

        if ($token === '') {
            return;
        }

        $session = $this->identities->resolve($token, $cabinet);

        if ($session === null) {
            // Carta sconosciuta: la si lega alla sessione attiva piu' recente dell'armadio.
            $target = Session::query()
                ->where('cabinet_id', $cabinet->id)
                ->where('status', 'active')
                ->latest('paid_at')
                ->first();

            if ($target instanceof Session) {
                $this->identities->bind($target, $token);
            }

            return;
        }

        if ($intent === 'checkout') {
            $this->sessions->checkout($session);

            return;
        }

        $identity = $session->identities()
            ->where('token_hash', Identity::hashToken($token))
            ->first();

        if ($identity instanceof Identity) {
            $this->sessions->reopen($session, $identity);
        }
    }
}
