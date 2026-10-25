<?php

namespace App\Monitoring;

use App\Events\MonitorAlert;
use Illuminate\Support\Facades\Log;

/**
 * Raises a monitoring alert. It is always logged; delivery channels (e-mail, Telegram) listen to the MonitorAlert event.
 */
class Alerts
{
    public static function send(string $title, string $body): void
    {
        Log::warning('[ScreamingRabbit uyarı] '.$title, ['body' => $body]);
        MonitorAlert::dispatch($title, $body);
    }
}
