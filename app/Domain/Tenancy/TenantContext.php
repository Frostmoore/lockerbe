<?php

namespace App\Domain\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Il tenant della richiesta in corso — singleton, per tutta la durata della richiesta
 * (o del job, o del comando artisan).
 *
 * E' l'UNICO posto da cui si impostano le variabili di sessione Postgres su cui si
 * reggono le policy RLS. Se qualcuno le impostasse altrove, ci ritroveremmo con due
 * fonti di verita' sull'unica cosa che tiene separati i clienti.
 *
 * Tre stati possibili:
 *
 *   TENANT  — c'e' un tenant. Le policy RLS filtrano tutto, il global scope pure.
 *             E' lo stato di ogni richiesta autenticata di un utente di un locale.
 *
 *   BYPASS  — nessun filtro. Serve a due cose, entrambe legittime:
 *             (a) `platform_admin` (noi), che per mestiere vede tutti i tenant;
 *             (b) il codice di sistema (migration, comandi artisan, e la fase di
 *                 autenticazione: per trovare l'utente che sta facendo login bisogna
 *                 poterlo cercare *prima* di sapere a che tenant appartiene).
 *
 *   VUOTO   — ne' l'uno ne' l'altro: non si vede NULLA. E' lo stato iniziale, ed e'
 *             deliberato. Un contesto dimenticato deve rompere in modo evidente, non
 *             mostrare i dati di tutti.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private bool $bypass = false;

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function hasBypass(): bool
    {
        return $this->bypass;
    }

    /** C'e' un tenant: da qui in poi si vede solo il suo. */
    public function setTenant(string $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->bypass = false;
        $this->apply();
    }

    /** platform_admin o codice di sistema: nessun filtro. */
    public function bypass(): void
    {
        $this->tenantId = null;
        $this->bypass = true;
        $this->apply();
    }

    /** Fail-closed: non si vede niente. */
    public function forget(): void
    {
        $this->tenantId = null;
        $this->bypass = false;
        $this->apply();
    }

    /**
     * Esegue una callback senza filtro tenant, ripristinando SEMPRE lo stato di prima.
     *
     * Usarla con parsimonia e con intenzione: ogni chiamata e' un punto in cui
     * l'isolamento fra clienti e' volontariamente spento.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runWithBypass(callable $callback): mixed
    {
        $tenantId = $this->tenantId;
        $bypass = $this->bypass;

        $this->bypass();

        try {
            return $callback();
        } finally {
            $this->tenantId = $tenantId;
            $this->bypass = $bypass;
            $this->apply();
        }
    }

    /**
     * Esegue una callback nel contesto di un tenant specifico (job in coda, comandi
     * schedulati), ripristinando lo stato precedente.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runForTenant(string $tenantId, callable $callback): mixed
    {
        $previousTenant = $this->tenantId;
        $previousBypass = $this->bypass;

        $this->setTenant($tenantId);

        try {
            return $callback();
        } finally {
            $this->tenantId = $previousTenant;
            $this->bypass = $previousBypass;
            $this->apply();
        }
    }

    /**
     * Riapplica lo stato corrente a una connessione appena aperta.
     *
     * Chiamato dal listener di ConnectionEstablished (AppServiceProvider): le variabili
     * di sessione vivono nella connessione, quindi una connessione nuova nasce senza
     * contesto — e, essendo le policy fail-closed, nascerebbe cieca.
     */
    public function syncToDatabase(): void
    {
        $this->apply(force: true);
    }

    /**
     * Propaga lo stato al database. Le policy RLS leggono queste due variabili.
     *
     * `set_config(..., false)` = a livello di SESSIONE, non di transazione.
     * ⚠️ Se un giorno si mettesse davanti un PgBouncer in *transaction pooling*, questo
     * non sopravviverebbe alla singola transazione e due tenant finirebbero per
     * condividere una connessione: allora servira' `SET LOCAL` dentro transazione,
     * oppure il session pooling. Vedi il debito aperto in codebase_reference.md.
     */
    private function apply(bool $force = false): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        // Se la connessione non e' ancora stata aperta, non la apriamo noi: un `artisan`
        // qualsiasi (config:cache, route:list) non deve fallire solo perche' il database
        // e' spento. Ci pensera' il listener di ConnectionEstablished, appena servira'.
        if (! $force && $connection->getRawPdo() === null) {
            return;
        }

        $connection->select(
            'SELECT set_config(?, ?, false), set_config(?, ?, false)',
            [
                'app.tenant_id', $this->tenantId ?? '',
                'app.bypass_rls', $this->bypass ? 'on' : '',
            ],
        );
    }
}
