<?php

namespace App\Monitoring;

use App\Events\MonitorAlert;
use App\Models\AlertEvent;
use Illuminate\Support\Facades\Log;

/**
 * Raises an alert: it is logged, kept in the notification center (alert_events) and delivered by the MonitorAlert
 * listener according to each user's choices (see Notifier).
 */
class Alerts
{
    /** Alert kinds each user can switch on or off on the notification settings page. */
    public const KINDS = [
        'down' => 'Site erişilemiyor (ve saatlik hatırlatma)',
        'up' => 'Site düzeldi',
        'slow' => 'Site yavaş',
        'ssl' => 'SSL sertifikası bitiyor',
        'page_change' => 'Kritik sayfada değişiklik (durum, noindex, canonical, title)',
        'new_issue' => 'Taramada yeni yüksek önemli sorun',
        'gsc_drop' => 'Search Console tıklamalarında düşüş',
        'crawl_failed' => 'Tarama hata verdi',
    ];

    /** Short names for lists. */
    public const LABELS = [
        'down' => 'Erişilemiyor', 'up' => 'Düzeldi', 'slow' => 'Yavaş', 'ssl' => 'SSL', 'page_change' => 'Kritik sayfa',
        'new_issue' => 'Yeni sorun', 'gsc_drop' => 'GSC düşüşü', 'crawl_failed' => 'Tarama hatası',
    ];

    /** Default severity of each kind (docs/08 §3.1); it decides the channels (Notifier). */
    public const SEVERITY = [
        'down' => 'critical', 'up' => 'critical', 'page_change' => 'critical',
        'slow' => 'high', 'ssl' => 'high', 'new_issue' => 'high', 'gsc_drop' => 'high', 'crawl_failed' => 'high',
    ];

    /** @var array<int, AlertEvent>|null alerts held back while collecting */
    private static ?array $buffer = null;

    public static function send(string $kind, string $title, string $body, ?int $projectId = null): void
    {
        Log::warning('[ScreamingRabbit uyarı] '.$title, ['body' => $body]);

        $event = AlertEvent::create([
            'kind' => $kind,
            'severity' => self::SEVERITY[$kind] ?? 'medium',
            'project_id' => $projectId,
            'title' => mb_substr($title, 0, 250),
            'body' => $body,
            'dedupe_key' => sha1($kind.'|'.$projectId.'|'.$title.'|'.$body),
            'created_at' => now(),
        ]);

        MonitorAlert::dispatch($kind, $title, $body, $projectId, $event->id);
    }

    /**
     * Runs $work and delivers the alerts it raised together afterwards, so several sites going down in the same run
     * reach each person as one message.
     */
    public static function collect(callable $work): mixed
    {
        self::$buffer = [];
        try {
            return $work();
        } finally {
            $alerts = self::$buffer;
            self::$buffer = null;
            if ($alerts !== []) {
                Notifier::deliver($alerts);
            }
        }
    }

    /** Keeps the alert for the end of collect(); false when not collecting. */
    public static function buffer(AlertEvent $event): bool
    {
        if (self::$buffer === null) {
            return false;
        }
        self::$buffer[] = $event;

        return true;
    }
}
