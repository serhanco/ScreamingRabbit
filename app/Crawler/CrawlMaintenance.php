<?php

namespace App\Crawler;

use App\Jobs\CrawlBatch;
use App\Models\Crawl;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping run by the scheduler: restarts crawls whose batch job was lost and removes old crawls.
 */
class CrawlMaintenance
{
    // Every batch touches its crawl, and the worker runs every minute, so 10 quiet minutes means the job was lost.
    private const STALLED_AFTER_MINUTES = 10;

    private const GIVE_UP_AFTER_MINUTES = 120;

    private const DELETE_CHUNK = 5000;

    /**
     * @return array{resumed: int, failed: int}
     */
    public static function rescueStalled(): array
    {
        $failed = 0;
        Crawl::query()->with('project')
            ->whereIn('status', ['pending', 'running'])
            ->where('updated_at', '<', now()->subMinutes(self::GIVE_UP_AFTER_MINUTES))
            ->get()
            ->each(function (Crawl $crawl) use (&$failed) {
                $crawl->update(['status' => 'failed', 'error' => 'Tarama uzun süre ilerlemedi ve durduruldu.', 'finished_at' => now()]);
                CrawlAlerts::failed($crawl);
                $failed++;
            });

        $resumed = 0;
        Crawl::query()
            ->whereIn('status', ['pending', 'running'])
            ->where('updated_at', '<', now()->subMinutes(self::STALLED_AFTER_MINUTES))
            ->each(function (Crawl $crawl) use (&$resumed) {
                $crawl->touch();
                CrawlBatch::dispatch($crawl->id);
                $resumed++;
            });

        return ['resumed' => $resumed, 'failed' => $failed];
    }

    /**
     * Keeps the newest finished crawls of each project and deletes the rest, in small chunks for shared hosting.
     */
    public static function prune(): int
    {
        $keep = max(1, (int) config('screamingrabbit.crawl_retention'));
        $deleted = 0;

        Project::query()->select('id')->each(function (Project $project) use ($keep, &$deleted) {
            $old = $project->crawls()
                ->whereIn('status', ['completed', 'failed', 'cancelled'])
                ->orderByDesc('id')
                ->skip($keep)
                ->take(PHP_INT_MAX)
                ->pluck('id');

            foreach ($old as $id) {
                self::delete($id);
                $deleted++;
            }
        });

        return $deleted;
    }

    /**
     * Deletes a crawl and its rows in small chunks (one large cascading delete can exceed shared hosting limits). The
     * health trend keeps its score; monthly reports keep their frozen copy.
     */
    public static function delete(int $id): void
    {
        foreach (['crawl_links', 'crawl_issues', 'crawl_image_refs', 'crawl_images', 'crawl_external_refs', 'crawl_external_links', 'crawl_pages'] as $table) {
            do {
                $ids = DB::table($table)->where('crawl_id', $id)->limit(self::DELETE_CHUNK)->pluck('id');
                DB::table($table)->whereIn('id', $ids)->delete();
            } while ($ids->count() === self::DELETE_CHUNK);
        }
        Crawl::whereKey($id)->delete();
    }
}
