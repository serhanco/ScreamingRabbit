<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class MonitorAlert
{
    use Dispatchable;

    public function __construct(public string $title, public string $body) {}
}
