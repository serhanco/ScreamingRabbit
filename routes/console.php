<?php

use App\Crawler\CrawlMaintenance;
use App\Monitoring\Channels;
use App\Monitoring\MonitorRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Tells the external heartbeat service the scheduler is alive; it warns Serhan if these pings stop (docs/08 §2.3).
Schedule::call(fn () => Channels::heartbeat())->name('heartbeat')->everyMinute();

// Uptime checks first: the queue worker below can keep this process busy for up to 50 seconds (docs/08 §2.3).
Schedule::call(fn () => MonitorRunner::run())
    ->name('monitors')
    ->everyMinute()
    ->withoutOverlapping(2);

// Hosting without a long-running process manager (Hostinger shared hosting) only has cron, and it does not allow
// starting sub-processes (proc_open). A single cron entry runs `schedule:run` every minute, and this drains the
// database queue inside that same PHP process for up to 50 seconds, so workers never overlap (docs/05, section 5.1).
Schedule::call(fn () => Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3]))
    ->name('queue-worker')
    ->everyMinute()
    ->withoutOverlapping(3);

Schedule::call(fn () => CrawlMaintenance::rescueStalled())->name('crawl-rescue')->everyFiveMinutes();

Schedule::call(fn () => CrawlMaintenance::prune())->name('crawl-prune')->dailyAt('04:00');

Schedule::call(function () {
    MonitorRunner::rollup(now()->subDay());
    MonitorRunner::prune();
})->name('monitor-daily')->dailyAt('00:10');
