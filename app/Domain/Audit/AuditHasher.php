<?php

namespace App\Domain\Audit;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Canonicalizzazione e hash dei record di audit — in un posto solo, e per forza.
 *
 * Il punto delicato: chi *scrive* ha in mano valori PHP (un Carbon, un array), chi
 * *verifica* rilegge da Postgres, che restituisce gli stessi dati **riformattati** —
 * `timestamptz` con un altro formato, `jsonb` con le chiavi riordinate e gli spazi
 * tolti. Hashando i valori "come capitano", scrittura e verifica produrrebbero hash
 * diversi sullo stesso identico record: la catena risulterebbe rotta ovunque, l'allarme
 * suonerebbe sempre, e nel giro di una settimana qualcuno lo spegnerebbe. Un sistema di
 * allarme che urla sempre e' peggio di nessun sistema di allarme.
 *
 * Da qui: entrambe le strade passano di qui, e i valori vengono normalizzati prima di
 * essere hashati.
 */
final class AuditHasher
{
    /**
     * I campi che entrano nell'hash. `id` no: e' assegnato dal database dopo il calcolo.
     * `prev_hash` e `hash` nemmeno, per ovvie ragioni.
     */
    private const HASHED_FIELDS = [
        'tenant_id', 'actor_type', 'actor_id', 'actor_role', 'source', 'action',
        'cabinet_id', 'locker_id', 'session_id', 'command_id',
        'result', 'error_code', 'ip', 'user_agent', 'request_id',
        'context', 'created_at',
    ];

    /**
     * @param  array<string, mixed>  $record
     */
    public static function hash(?string $previousHash, array $record): string
    {
        return hash('sha256', ($previousHash ?? '').self::canonical($record));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function canonical(array $record): string
    {
        $payload = [];

        foreach (self::HASHED_FIELDS as $field) {
            $payload[$field] = self::normalize($field, $record[$field] ?? null);
        }

        // Le chiavi sono gia' in ordine fisso (HASHED_FIELDS), ma ksort rende esplicito
        // che l'ordine non dipende da come il record e' stato costruito.
        ksort($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function normalize(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($field === 'created_at') {
            $date = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse((string) $value);

            // Sempre UTC, sempre al microsecondo: il fuso e il formato di Postgres non
            // devono poter cambiare l'hash.
            return $date->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        if ($field === 'context') {
            /** @var array<string, mixed> $decoded */
            $decoded = is_array($value)
                ? $value
                : (array) json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

            ksort($decoded);

            return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
