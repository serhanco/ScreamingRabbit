<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class MonitorAlert
{
    use Dispatchable;

    public function __construct(public string $kind, public string $title, public string $body, public ?int $projectId = null, public ?int $eventId = null) {}
}
