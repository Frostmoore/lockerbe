<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ruoli e permessi (piano §4). Idempotente: si puo' rieseguire senza danni.
 *
 * ⚠️ Qui NON ci sono utenti e NON ci sono password. Gli account si creano a mano
 * (tinker) sull'ambiente in cui servono: una password in chiaro in un file versionato
 * e' una password bruciata, e questo repository un giorno potrebbe finire su GitHub.
 *
 * La matrice dei permessi non e' arbitraria. Le due righe che contano:
 *
 *  - `locker.open_all` NON e' dato a tenant_staff. Aprire tutti i vani di un armadio in
 *    un colpo e' l'azione piu' pericolosa del sistema (svuota il guardaroba): resta al
 *    gestore, che ne risponde.
 *  - `ota.manage` e' solo di platform_admin. Un firmware difettoso spedito a tutti
 *    mette fuori uso gli armadi di TUTTI i clienti insieme, e da remoto non si
 *    recuperano.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'cabinet.view', 'cabinet.manage',
        'locker.view', 'locker.open', 'locker.open_all', 'locker.service',
        'session.view', 'session.checkout',
        'user.manage', 'audit.view', 'ota.manage', 'tenant.manage',
    ];

    /** @var array<string, list<string>> */
    private const ROLES = [
        // Noi. Unico a poter fare OTA e onboarding dei tenant.
        'platform_admin' => self::PERMISSIONS,

        // Il gestore del locale: tutti gli armadi del PROPRIO tenant.
        'tenant_admin' => [
            'cabinet.view', 'cabinet.manage',
            'locker.view', 'locker.open', 'locker.open_all', 'locker.service',
            'session.view', 'session.checkout',
            'user.manage', 'audit.view',
        ],

        // Chi lavora al guardaroba: operativita', niente gestione.
        'tenant_staff' => [
            'cabinet.view',
            'locker.view', 'locker.open', 'locker.service',
            'session.view', 'session.checkout',
            'audit.view',
        ],

        // `end_user` NON esiste come ruolo: il cliente che deposita il cappotto non ha
        // un account (piano §4). Riapre il proprio vano con la carta o un token di
        // sessione. Se un giorno comparisse qui, vorrebbe dire che abbiamo sbagliato.
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
