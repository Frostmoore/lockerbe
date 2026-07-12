<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Impostazioni di piattaforma, modificabili a sistema acceso da `platform_admin`.
 *
 * Non e' tenant-scoped: e' la piattaforma. Non ha RLS.
 *
 * Perche' non stanno in `.env`: per cambiare una variabile d'ambiente serve un deploy,
 * e le cose che l'amministratore deve poter accendere e spegnere (prima fra tutte la
 * MFA) non possono richiedere un deploy.
 *
 * @property string $key
 * @property mixed $value
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    private const CACHE_PREFIX = 'platform_setting:';

    public static function get(string $key, mixed $default = null): mixed
    {
        /** @var array{value: mixed}|null $cached */
        $cached = Cache::rememberForever(
            self::CACHE_PREFIX.$key,
            fn (): ?array => ($row = static::query()->find($key)) !== null
                ? ['value' => $row->value]
                : null,
        );

        return $cached === null ? $default : $cached['value'];
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
