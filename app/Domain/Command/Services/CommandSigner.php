<?php

namespace App\Domain\Command\Services;

use App\Models\Command;
use App\Models\Device;

/**
 * Firma HMAC-SHA256 dei comandi (piano §8.6).
 *
 * ⚠️ **Perche' firmare, se il canale sara' gia' cifrato (TLS)?** Perche' il TLS protegge il
 * *filo*, non il *messaggio*. Chi dovesse ottenere accesso al broker — un bug nelle ACL, una
 * credenziale di servizio finita in mano sbagliata — potrebbe pubblicare un `open` sul topic
 * di un armadio qualunque. Il device, non trovando una firma valida, lo **scarta**.
 *
 * La chiave e' **per-device**: compromettere un chiosco non permette di firmare comandi per
 * gli altri.
 */
final class CommandSigner
{
    /**
     * Firma su `id|type|locker|expires_at` — gli stessi campi che il device rileggera' dal
     * payload. Cambiare uno solo di questi valori invalida la firma: in particolare
     * `expires_at`, che e' cio' che impedisce di rigiocare un comando vecchio.
     */
    public function sign(Command $command, Device $device): string
    {
        return hash_hmac('sha256', $this->canonical($command), (string) $device->signing_secret);
    }

    public function verify(Command $command, Device $device, string $signature): bool
    {
        return hash_equals($this->sign($command, $device), $signature);
    }

    private function canonical(Command $command): string
    {
        return implode('|', [
            $command->id,
            $command->type,
            $command->locker_id ?? '',
            $command->expires_at->utc()->toIso8601String(),
        ]);
    }
}
