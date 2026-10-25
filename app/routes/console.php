<?php

use App\Crawler\CrawlMaintenance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Hosting without a long-running process manager (Hostinger shared hosting) only has cron, and it does not allow
// starting sub-processes (proc_open). A single cron entry runs `schedule:run` every minute, and this drains the
// database queue inside that same PHP process for up to 50 seconds, so workers never overlap (docs/05, section 5.1).
Schedule::call(fn () => Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3]))
    ->name('queue-worker')
    ->everyMinute()
    ->withoutOverlapping(3);

Schedule::call(fn () => CrawlMaintenance::rescueStalled())->name('crawl-rescue')->everyFiveMinutes();

Schedule::call(fn () => CrawlMaintenance::prune())->name('crawl-prune')->dailyAt('04:00');
