<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Application settings changed from the panel (so nothing has to be edited in .env on the server).
 * Secrets are stored encrypted with the app key.
 */
class Setting extends Model
{
    public const SECRETS = ['smtp_password', 'telegram_bot_token'];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::query()->whereKey($key)->value('value');
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array($key, self::SECRETS, true) ? rescue(fn () => Crypt::decryptString($value), $default, false) : $value;
    }

    public static function put(string $key, mixed $value): void
    {
        $value = $value === null || $value === '' ? null : (string) $value;
        if ($value !== null && in_array($key, self::SECRETS, true)) {
            $value = Crypt::encryptString($value);
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
