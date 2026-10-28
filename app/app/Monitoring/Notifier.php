<?php

namespace App\Monitoring;

use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides, per user, how each alert goes out (docs/08 §3.3):
 * critical → Telegram and e-mail at once; high → Telegram (e-mail when the user has no Telegram);
 * medium/low → only the weekly summary. The same alert is not repeated within REPEAT_HOURS, non-critical alerts
 * wait out the user's quiet hours, and three or more sites going down together arrive as one message.
 */
class Notifier
{
    public const REPEAT_HOURS = 6;

    public const MASS_OUTAGE = 3;

    /** Uptime alerts manage their own repeats (down, hourly reminder, up). */
    private const OWN_REPEATS = ['down', 'up'];

    /**
     * @param  array<int, AlertEvent>  $alerts
     */
    public static function deliver(array $alerts): void
    {
        $alerts = collect($alerts);
        $users = $alerts->flatMap(fn (AlertEvent $alert) => Channels::recipients($alert->kind, $alert->project_id))->unique('id');

        foreach ($users as $user) {
            $mine = $alerts->filter(fn (AlertEvent $alert) => $user->wantsAlert($alert->kind) && $user->canSeeProject($alert->project_id));
            $now = [];
            foreach ($mine as $alert) {
                $status = self::statusFor($user, $alert);
                $delivery = AlertDelivery::create(['alert_event_id' => $alert->id, 'user_id' => $user->id, 'status' => $status, 'created_at' => now()]);
                if ($status === 'sent') {
                    $now[] = [$alert, $delivery];
                }
            }
            self::sendNow($user, $now);
        }
    }

    public static function statusFor(User $user, AlertEvent $alert): string
    {
        $repeat = ! in_array($alert->kind, self::OWN_REPEATS, true) && AlertDelivery::query()
            ->where('user_id', $user->id)->where('status', 'sent')->where('created_at', '>=', now()->subHours(self::REPEAT_HOURS))
            ->whereHas('event', fn ($query) => $query->where('dedupe_key', $alert->dedupe_key)->whereKeyNot($alert->id))
            ->exists();

        return match (true) {
            $repeat => 'suppressed',
            in_array($alert->severity, ['medium', 'low'], true) => 'digest',
            $alert->severity !== 'critical' && self::inQuietHours($user) => 'held',
            default => 'sent',
        };
    }

    public static function inQuietHours(User $user, ?Carbon $at = null): bool
    {
        if ($user->quiet_from === null || $user->quiet_to === null || $user->quiet_from === $user->quiet_to) {
            return false;
        }
        $hour = (int) ($at ?? now())->copy()->timezone(config('screamingrabbit.alerts.timezone'))->format('G');

        return $user->quiet_from < $user->quiet_to
            ? $hour >= $user->quiet_from && $hour < $user->quiet_to
            : $hour >= $user->quiet_from || $hour < $user->quiet_to;
    }

    /**
     * After quiet hours: everything that waited goes out as one message.
     */
    public static function releaseHeld(): int
    {
        $released = 0;
        $held = AlertDelivery::query()->with('event')->where('status', 'held')->orderBy('id')->get()->groupBy('user_id');
        foreach ($held as $userId => $deliveries) {
            $user = User::find($userId);
            if ($user === null || self::inQuietHours($user)) {
                continue;
            }
            $lines = $deliveries->map(fn (AlertDelivery $d) => self::time($d->event->created_at).' · '.$d->event->title."\n".$d->event->body);
            $channels = self::send($user, 'high', 'Sessiz saatlerde gelen '.$deliveries->count().' uyarı', $lines->implode("\n\n"));
            AlertDelivery::whereKey($deliveries->modelKeys())->update(['status' => 'sent', 'sent_at' => now(), 'channels' => json_encode($channels)]);
            $released += $deliveries->count();
        }

        return $released;
    }

    /**
     * Monday morning e-mail: what happened last week on the user's projects.
     */
    public static function weeklyDigest(): int
    {
        $sent = 0;
        $since = now()->subDays(7);
        foreach (User::query()->where('is_active', true)->where('weekly_digest', true)->get() as $user) {
            $events = AlertEvent::query()->with('project')->where('created_at', '>=', $since)->orderBy('created_at')->get()
                ->filter(fn (AlertEvent $event) => $user->wantsAlert($event->kind) && $user->canSeeProject($event->project_id));
            if ($events->isEmpty()) {
                continue;
            }

            $counts = $events->countBy(fn (AlertEvent $event) => $event->kindLabel())->map(fn ($n, $label) => "{$label}: {$n}")->implode("\n");
            $list = $events->take(-40)->map(fn (AlertEvent $event) => self::time($event->created_at).' · '.$event->title)->implode("\n");
            $body = "Son 7 gün, {$events->count()} uyarı.\n\n{$counts}\n\nAyrıntı:\n{$list}\n\nTümü: ".url('/admin/bildirimler');

            if (Channels::email($user->alert_email, 'ScreamingRabbit haftalık özet', $body)) {
                AlertDelivery::query()->where('user_id', $user->id)->where('status', 'digest')->update(['sent_at' => now()]);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  array<int, array{0: AlertEvent, 1: AlertDelivery}>  $items
     */
    private static function sendNow(User $user, array $items): void
    {
        $items = collect($items);
        $downs = $items->filter(fn (array $item) => $item[0]->kind === 'down');
        $groups = $downs->count() >= self::MASS_OUTAGE
            ? [$downs->values(), ...$items->reject(fn (array $item) => $item[0]->kind === 'down')->map(fn ($item) => collect([$item]))->values()->all()]
            : $items->map(fn ($item) => collect([$item]))->values()->all();

        foreach ($groups as $group) {
            /** @var Collection<int, array{0: AlertEvent, 1: AlertDelivery}> $group */
            [$first] = $group->first();
            [$title, $body] = $group->count() === 1
                ? [$first->title, $first->body]
                : ['🔴 Toplu kesinti: '.$group->count().' site erişilemiyor', $group->map(fn ($item) => $item[0]->body)->implode("\n").
                    "\n\nAynı anda birden çok site düştü; büyük olasılıkla ortak hosting veya ağ sorunu."];
            $channels = self::send($user, $first->severity, $title, $body);
            AlertDelivery::whereKey($group->map(fn ($item) => $item[1]->id)->all())->update(['sent_at' => now(), 'channels' => json_encode($channels)]);
        }
    }

    /**
     * @return array<string, bool|null>
     */
    private static function send(User $user, string $severity, string $title, string $body): array
    {
        $telegramReady = filled($user->telegram_chat_id) && filled(Setting::get('telegram_bot_token'));
        if ($severity === 'critical' || ! $telegramReady) {
            return Channels::toUser($user, $title, $body);
        }

        return ['email' => null, 'telegram' => Channels::telegram($user->telegram_chat_id, $title, $body)];
    }

    /** The notification center keeps half a year. */
    public static function prune(): int
    {
        return AlertEvent::where('created_at', '<', now()->subDays(180))->delete();
    }

    private static function time(Carbon $at): string
    {
        return $at->copy()->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m H:i');
    }
}
