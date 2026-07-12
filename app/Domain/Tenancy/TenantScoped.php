<?php

namespace App\Domain\Tenancy;

/**
 * Implementata da ogni modello che appartiene a un tenant.
 *
 * Esiste per una ragione pratica: `Tenant` stesso e' tenant-scoped, ma la sua colonna
 * di appartenenza e' `id`, non `tenant_id`. Senza questa interfaccia lo scope dovrebbe
 * indovinare, e indovinare male qui significa mostrare a un cliente i dati di un altro.
 */
interface TenantScoped
{
    /** La colonna che lega il record al tenant. */
    public function getTenantColumn(): string;
}
