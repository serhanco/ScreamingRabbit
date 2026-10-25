<?php

namespace App\Listeners;

use App\Events\MonitorAlert;
use App\Monitoring\Channels;

class DeliverMonitorAlert
{
    public function handle(MonitorAlert $event): void
    {
        Channels::deliver($event->title, $event->body);
    }
}
