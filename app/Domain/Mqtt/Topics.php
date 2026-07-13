<?php

namespace App\Domain\Mqtt;

use App\Models\Cabinet;

/**
 * I topic MQTT (piano §9). **Un solo posto in cui sono scritti.**
 *
 * Se fossero costruiti a mano in tre file diversi, prima o poi due divergerebbero — e due
 * topic che divergono significano un armadio che non riceve i comandi, o peggio, un armadio
 * che riceve quelli di un altro.
 *
 *   locker/t/{tenant}/cab/{cabinet}/cmd      server → device   (comandi)
 *   locker/t/{tenant}/cab/{cabinet}/evt      device → server   (eventi)
 *   locker/t/{tenant}/cab/{cabinet}/status   device → server   (LWT: vivo/morto)
 */
final class Topics
{
    public static function command(Cabinet $cabinet): string
    {
        return self::base($cabinet->tenant_id, $cabinet->id).'/cmd';
    }

    public static function event(Cabinet $cabinet): string
    {
        return self::base($cabinet->tenant_id, $cabinet->id).'/evt';
    }

    public static function status(Cabinet $cabinet): string
    {
        return self::base($cabinet->tenant_id, $cabinet->id).'/status';
    }

    /** Tutti gli eventi di tutti i locali: lo sottoscrive solo il nostro listener. */
    public static function allEvents(): string
    {
        return 'locker/t/+/cab/+/evt';
    }

    public static function allStatus(): string
    {
        return 'locker/t/+/cab/+/status';
    }

    /**
     * Estrae il cabinet da un topic.
     *
     * @return array{tenant: string, cabinet: string}|null
     */
    public static function parse(string $topic): ?array
    {
        if (preg_match('#^locker/t/([^/]+)/cab/([^/]+)/(cmd|evt|status)$#', $topic, $m) !== 1) {
            return null;
        }

        return ['tenant' => $m[1], 'cabinet' => $m[2]];
    }

    private static function base(string $tenantId, string $cabinetId): string
    {
        return "locker/t/{$tenantId}/cab/{$cabinetId}";
    }
}
