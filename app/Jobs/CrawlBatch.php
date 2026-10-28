<?php

namespace App\Jobs;

use App\Crawler\CrawlAlerts;
use App\Crawler\CrawlRunner;
use App\Models\Crawl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CrawlBatch implements ShouldQueue
{
    use Queueable;

    // The queue worker on shared hosting is stopped after about 50 seconds, so each batch stays well below that.
    public int $timeout = 55;

    public int $tries = 1;

    public function __construct(public int $crawlId) {}

    public function handle(): void
    {
        $crawl = Crawl::find($this->crawlId);

        if ($crawl === null || ! $crawl->isActive()) {
            return;
        }

        // Heartbeat for CrawlMaintenance::rescueStalled().
        $crawl->touch();

        if ((new CrawlRunner($crawl))->run()) {
            self::dispatch($this->crawlId);
        }
    }

    public function failed(Throwable $exception): void
    {
        $crawl = Crawl::with('project')->find($this->crawlId);
        if ($crawl === null) {
            return;
        }
        $crawl->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'finished_at' => now()]);
        CrawlAlerts::failed($crawl);
    }
}
