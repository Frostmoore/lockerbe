<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sede di un tenant. Opzionale (piano §2): prevista per non doverla aggiungere dopo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 */
class Site extends Model implements TenantScoped
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name', 'address', 'timezone', 'site_id'];
}
