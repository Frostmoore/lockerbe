<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le impostazioni che l'amministratore accende e spegne a sistema acceso.
 *
 * Oggi ce n'e' una sola — l'obbligo di MFA — ma e' quella che il piano §4 richiede e che
 * senza questa rotta si potrebbe cambiare solo con un deploy.
 *
 * Solo `platform_admin`: e' una scelta di piattaforma, non di un singolo locale. Un
 * tenant puo' comunque **alzare** l'asticella per i propri utenti (`tenants.settings`),
 * mai abbassarla.
 */
final class PlatformSettingController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'settings' => [
                'security.require_mfa' => PlatformSetting::get('security.require_mfa', false),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'security.require_mfa' => ['required', 'boolean'],
        ]);

        $required = (bool) data_get($data, 'security.require_mfa');

        PlatformSetting::set('security.require_mfa', $required);

        // Accendere o spegnere la MFA e' un cambio di postura di sicurezza dell'intera
        // piattaforma: finisce nell'audit come qualsiasi apertura di vano.
        $this->audit->log('platform.settings.updated', [
            'context' => ['security.require_mfa' => $required],
        ]);

        return $this->index();
    }
}
