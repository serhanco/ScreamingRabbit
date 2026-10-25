<?php

use Illuminate\Support\Facades\Schedule;

// Hosting without a long-running process manager (Hostinger shared hosting) only has cron.
// A single cron entry runs `schedule:run` every minute, and this drains the database queue
// for up to 50 seconds so workers never overlap (docs/05, section 5.1).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
