<?php

namespace App\Listeners;

use App\Events\MonitorAlert;
use App\Models\AlertEvent;
use App\Monitoring\Alerts;
use App\Monitoring\Notifier;

class DeliverMonitorAlert
{
    public function handle(MonitorAlert $event): void
    {
        $alert = $event->eventId ? AlertEvent::find($event->eventId) : null;
        if ($alert === null) {
            return;
        }
        // Inside Alerts::collect() the alerts go out together at the end, so a mass outage becomes one message.
        if (! Alerts::buffer($alert)) {
            Notifier::deliver([$alert]);
        }
    }
}
