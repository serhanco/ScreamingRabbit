<?php

namespace App\Monitoring;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers alerts to every user who wants that kind: by e-mail through the shared SMTP mailbox and by Telegram
 * through the shared bot, each to the user's own address and chat.
 */
class Channels
{
    /**
     * @return array<int, array<string, bool|null>> user id => channel results
     */
    public static function deliver(string $kind, string $title, string $body): array
    {
        return User::query()->orderBy('id')->get()
            ->filter(fn (User $user) => $user->wantsAlert($kind))
            ->mapWithKeys(fn (User $user) => [$user->id => self::toUser($user, $title, $body)])
            ->all();
    }

    /**
     * @return array<string, bool|null> channel => true sent, false failed, null not configured
     */
    public static function toUser(User $user, string $title, string $body): array
    {
        return ['email' => self::email($user->alert_email, $title, $body), 'telegram' => self::telegram($user->telegram_chat_id, $title, $body)];
    }

    public static function email(?string $to, string $title, string $body): ?bool
    {
        $username = Setting::get('smtp_username');
        if (! $to || ! $username) {
            return null;
        }

        $port = (int) Setting::get('smtp_port', config('screamingrabbit.alerts.smtp_port'));
        config(['mail.mailers.alerts' => ['transport' => 'smtp', 'timeout' => 10, 'scheme' => $port === 465 ? 'smtps' : 'smtp'] + self::smtpOptions()]);

        try {
            Mail::mailer('alerts')->raw($body."\n\n— ".config('screamingrabbit.brand.product'), function ($message) use ($to, $title, $username) {
                $message->from($username, config('screamingrabbit.brand.product'))->to($to)->subject($title);
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Uyarı e-postası gönderilemedi: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Mailer options read from the settings page, falling back to the defaults in config/screamingrabbit.php.
     *
     * @return array<string, mixed>
     */
    private static function smtpOptions(): array
    {
        $options = [];
        foreach (['host' => 'smtp_host', 'port' => 'smtp_port', 'username' => 'smtp_username', 'password' => 'smtp_password'] as $option => $setting) {
            $options[$option] = Setting::get($setting, config('screamingrabbit.alerts.'.$setting));
        }

        return $options;
    }

    /** Why the last Telegram send failed, in Telegram's words, so the settings page can say what to fix. */
    public static ?string $telegramError = null;

    public static function telegram(?string $chat, string $title, string $body): ?bool
    {
        self::$telegramError = null;
        $token = Setting::get('telegram_bot_token');
        if (! $token || ! $chat) {
            return null;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => "{$title}\n\n{$body}",
                'disable_web_page_preview' => true,
            ]);
            self::$telegramError = $response->successful() ? null : (string) $response->json('description', 'HTTP '.$response->status());

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Telegram uyarısı gönderilemedi: '.$e->getMessage());

            return false;
        }
    }

    /**
     * The chat that most recently wrote to the bot, so the user only has to send it a message instead of finding an id.
     */
    public static function findTelegramChat(string $token): ?string
    {
        $updates = rescue(fn () => Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getUpdates")->json('result'), null, false);
        $last = is_array($updates) ? end($updates) : null;
        $chat = $last['message']['chat']['id'] ?? $last['my_chat_member']['chat']['id'] ?? null;

        return $chat === null ? null : (string) $chat;
    }

    /** Pings the external heartbeat service (e.g. Healthchecks.io) so it can warn when this app itself is down. */
    public static function heartbeat(): void
    {
        if ($url = Setting::get('heartbeat_url')) {
            rescue(fn () => Http::timeout(5)->get($url), null, false);
        }
    }
}
