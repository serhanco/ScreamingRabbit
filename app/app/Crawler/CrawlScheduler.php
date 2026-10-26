<?php

namespace App\Crawler;

use App\Models\Project;

/**
 * Starts the crawls projects ask for on a schedule. Runs from the every-ten-minutes scheduler tick, only at night
 * (client sites are quietest and the shared host is idle), and never while a crawl of the project is still going.
 */
class CrawlScheduler
{
    public const FREQUENCIES = ['daily' => 'Her gün', 'weekly' => 'Her hafta', 'monthly' => 'Her ay'];

    private const DAYS = ['daily' => 1, 'weekly' => 7, 'monthly' => 30];

    /** First and last hour (site time) in which scheduled crawls may start. */
    private const WINDOW = [1, 5];

    public static function runDue(): int
    {
        if (! now()->between(now()->setTime(self::WINDOW[0], 0), now()->setTime(self::WINDOW[1], 59))) {
            return 0;
        }

        $started = 0;
        Project::query()->whereNotNull('crawl_schedule')->each(function (Project $project) use (&$started) {
            if (self::isDue($project)) {
                CrawlRunner::start($project);
                $started++;
            }
        });

        return $started;
    }

    public static function isDue(Project $project): bool
    {
        $days = self::DAYS[$project->crawl_schedule] ?? null;
        if (! $days || $project->crawls()->whereIn('status', ['pending', 'running'])->exists()) {
            return false;
        }

        $last = $project->crawls()->reorder('id', 'desc')->first();

        // Leave a few hours of slack so a crawl that took long yesterday does not push today's start into the next day.
        return ! $last || $last->created_at->lte(now()->subDays($days)->addHours(6));
    }
}
