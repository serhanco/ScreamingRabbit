<?php

use App\Crawler\CrawlMaintenance;
use App\Crawler\CrawlScheduler;
use App\Crawler\RedirectChecker;
use App\Google\GscDrop;
use App\Google\GscSync;
use App\Models\ActivityLog;
use App\Monitoring\Channels;
use App\Monitoring\MonitorRunner;
use App\Monitoring\Notifier;
use App\Monitoring\PageWatcher;
use App\Performance\PageSpeed;
use App\Reports\MonthlyReports;
use App\WordPress\Connector;
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

// Critical pages: hourly or daily, a handful per run so a run stays short.
Schedule::call(fn () => PageWatcher::runDue())->name('page-watch')->everyFiveMinutes()->withoutOverlapping(5);

// Search Console numbers: one short window per project each run, so the 16-month backfill spreads over many runs.
Schedule::call(fn () => GscSync::runAll())->name('gsc-sync')->everyTenMinutes()->withoutOverlapping(10);

// Site migration checks: a few old URLs each minute until the list is done.
Schedule::call(fn () => RedirectChecker::runDue())->name('redirect-checks')->everyMinute()->withoutOverlapping(5);

// PageSpeed Insights: one sample page per finished crawl each run (a call takes 10-30 seconds).
Schedule::call(fn () => PageSpeed::runDue())->name('pagespeed')->everyFiveMinutes()->withoutOverlapping(10);

// Projects that asked for daily/weekly/monthly crawls get them started overnight.
// Inventory from the WordPress Connector plugin, refreshed daily per site (docs/07 §3).
Schedule::call(fn () => Connector::runDue())->name('wordpress-sync')->hourly()->withoutOverlapping(10);

Schedule::call(fn () => CrawlScheduler::runDue())->name('crawl-schedule')->everyTenMinutes()->withoutOverlapping(10);

Schedule::call(fn () => CrawlMaintenance::rescueStalled())->name('crawl-rescue')->everyFiveMinutes();

// Monthly client reports: drafted in the first week of each month (05:00 UTC, after the nightly crawls), approved by hand.
Schedule::call(fn () => MonthlyReports::generateDue())->name('monthly-reports')->dailyAt('05:00');

Schedule::call(fn () => CrawlMaintenance::prune())->name('crawl-prune')->dailyAt('04:00');

Schedule::call(function () {
    MonitorRunner::rollup(now()->subDay());
    MonitorRunner::prune();
    Notifier::prune();
    ActivityLog::query()->where('created_at', '<', now()->subDays(ActivityLog::RETENTION_DAYS))->delete();
})->name('monitor-daily')->dailyAt('00:10');

// Notification center: alerts held during someone's quiet hours go out when they end; GSC drops are checked each
// morning; the weekly summary goes out on Monday. The server runs on UTC: 06:00 is 09:00 in Istanbul.
Schedule::call(fn () => Notifier::releaseHeld())->name('alerts-release')->everyTenMinutes();
Schedule::call(fn () => GscDrop::runAll())->name('gsc-drop')->dailyAt('06:00');
Schedule::call(fn () => Notifier::weeklyDigest())->name('alerts-weekly')->weeklyOn(1, '06:00');
