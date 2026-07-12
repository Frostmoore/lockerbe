<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * L'unico punto da cui si scrive nell'audit log (piano §14).
 *
 * Perche' "unico": il registro e' incatenato con hash (ogni record contiene l'hash del
 * precedente). Se qualcuno inserisse una riga passando da Eloquent, la catena si
 * spezzerebbe e `audit:verify-chain` griderebbe alla manomissione — per un errore
 * nostro, non per un attacco.
 *
 * Non e' burocrazia. E' cio' che si guarda il giorno che un cliente dice "mi hanno
 * rubato il cappotto dall'armadietto 14": chi lo ha aperto, quando, da dove, con quale
 * comando, e cosa ha risposto il device.
 */
final class AuditLogger
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function log(string $action, array $attributes = []): void
    {
        $record = $this->buildRecord($action, $attributes);

        // Il `tenant_id` del record e' gia' stato catturato dal contesto reale, sopra.
        // La SCRITTURA avviene in bypass, e per un motivo preciso: la catena di hash e'
        // unica per tutta la piattaforma, quindi per calcolare `prev_hash` bisogna
        // leggere l'ultimo record **globale**. Sotto RLS un utente di un tenant vedrebbe
        // solo i propri: leggerebbe un `prev_hash` sbagliato e spezzerebbe la catena a
        // ogni scrittura, senza che nessuno abbia manomesso niente.
        $this->context->runWithBypass(function () use ($record): void {
            DB::transaction(function () use ($record): void {
                // Due scritture in parallelo leggerebbero lo stesso `prev_hash` e
                // produrrebbero una biforcazione: catena rotta per una race, non per un
                // attacco. Il lock serializza le scritture dell'audit, e solo quelle.
                DB::select("SELECT pg_advisory_xact_lock(hashtext('audit_logs'))");

                /** @var string|null $previousHash */
                $previousHash = DB::table('audit_logs')->orderByDesc('id')->value('hash');

                $record['prev_hash'] = $previousHash;
                $record['hash'] = AuditHasher::hash($previousHash, $record);

                DB::table('audit_logs')->insert($record);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function buildRecord(string $action, array $attributes): array
    {
        $actor = $attributes['actor'] ?? auth()->user();
        unset($attributes['actor']);

        $request = request();
        $isHttp = ! app()->runningInConsole();

        $record = array_merge([
            'tenant_id' => $this->context->id(),
            'actor_type' => $actor instanceof User ? 'user' : 'system',
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'actor_role' => $actor instanceof User ? $actor->getRoleNames()->first() : null,
            'source' => $isHttp ? 'api' : 'system',
            'action' => $action,
            'cabinet_id' => null,
            'locker_id' => null,
            'session_id' => null,
            'command_id' => null,
            'result' => 'ok',
            'error_code' => null,
            'ip' => $isHttp ? $request->ip() : null,
            'user_agent' => $isHttp ? $request->userAgent() : null,
            'request_id' => (string) Str::uuid7(),
            'context' => [],

            // Stringa canonica, non un oggetto Carbon. Motivo: passando un Carbon,
            // Laravel lo serializza per Postgres con il formato 'Y-m-d H:i:s' — cioe'
            // **buttando via i microsecondi**. L'hash, calcolato prima dell'insert, li
            // conterrebbe; la verifica, che rilegge dal database, no. La catena
            // risulterebbe rotta su ogni singolo record, e l'allarme diventerebbe rumore.
            'created_at' => now()->utc()->format('Y-m-d H:i:s.u'),
        ], $attributes);

        // Il contesto viaggia come array fino a qui, poi diventa JSON: l'hash lo
        // normalizza comunque (AuditHasher), ma il DB vuole una stringa.
        $record['context'] = json_encode($record['context'], JSON_THROW_ON_ERROR);

        return $record;
    }
}
