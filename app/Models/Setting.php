<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Application settings changed from the panel (so nothing has to be edited in .env on the server).
 * Secrets are stored encrypted with the app key.
 */
class Setting extends Model
{
    use LogsActivity;

    public const SECRETS = ['smtp_password', 'telegram_bot_token', 'google_client_secret', 'pagespeed_api_key',
        'ai_key_anthropic', 'ai_key_openai', 'ai_key_gemini', 'ai_key_mistral', 'ai_key_compatible'];

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
        // Saving a settings form writes every field; only real changes are stored (and logged). Secrets are compared
        // decrypted, because each encryption of the same value looks different.
        if (static::query()->whereKey($key)->exists() && static::get($key) === $value) {
            return;
        }
        if ($value !== null && in_array($key, self::SECRETS, true)) {
            $value = Crypt::encryptString($value);
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function activityLabel(): string
    {
        return 'Ortak ayar: '.$this->key;
    }
}
