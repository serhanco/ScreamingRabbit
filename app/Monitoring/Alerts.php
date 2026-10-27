<?php

namespace App\Monitoring;

use App\Events\MonitorAlert;
use Illuminate\Support\Facades\Log;

/**
 * Raises a monitoring alert. It is always logged; delivery channels (e-mail, Telegram) listen to the MonitorAlert event.
 */
class Alerts
{
    /** Alert kinds each user can switch on or off on the notification settings page. */
    public const KINDS = [
        'down' => 'Site erişilemiyor (ve saatlik hatırlatma)',
        'up' => 'Site düzeldi',
        'slow' => 'Site yavaş',
        'ssl' => 'SSL sertifikası bitiyor',
    ];

    public static function send(string $kind, string $title, string $body, ?int $projectId = null): void
    {
        Log::warning('[ScreamingRabbit uyarı] '.$title, ['body' => $body]);
        MonitorAlert::dispatch($kind, $title, $body, $projectId);
    }
}
