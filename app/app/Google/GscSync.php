<?php

namespace App\Google;

use App\Crawler\UrlNormalizer;
use App\Models\Project;
use App\Tasks\ImpactMeter;
use App\Tasks\TaskBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies Search Analytics clicks/impressions/position per page and day into gsc_page_days. Each run fetches one
 * window per project, so the 16-month backfill spreads over several short runs on shared hosting.
 */
class GscSync
{
    /** Search Console keeps 16 months of data. */
    private const HISTORY_DAYS = 486;

    private const WINDOW_DAYS = 30;

    /** Recent days are still being finalised by Google, so they are fetched again on the next run. */
    private const REFRESH_DAYS = 3;

    /** Data for the last two days is usually not there yet. */
    private const LAG_DAYS = 2;

    private const ROW_LIMIT = 25000;

    private const MAX_PAGES = 4;

    public static function runAll(): void
    {
        if (! GoogleClient::connected()) {
            return;
        }

        Project::query()->whereNotNull('gsc_property')->each(function (Project $project) {
            try {
                self::run($project);
                if ($project->gsc_queries_synced_on?->isToday() !== true) {
                    self::syncQueries($project);
                    // Fresh query data once a day: refresh the Search Console tasks too.
                    TaskBuilder::sync($project);
                    ImpactMeter::run($project);
                }
                UrlInspector::run($project);
            } catch (\Throwable $e) {
                Log::error("GSC senkronu başarısız ({$project->name}): ".$e->getMessage());
            }
        });
    }

    /**
     * @return int rows stored
     */
    public static function run(Project $project): int
    {
        $end = CarbonImmutable::today()->subDays(self::LAG_DAYS);
        $oldest = $end->subDays(self::HISTORY_DAYS);
        $first = DB::table('gsc_page_days')->where('project_id', $project->id)->min('day');
        $last = DB::table('gsc_page_days')->where('project_id', $project->id)->max('day');

        if ($last === null) {
            // Nothing yet: start with the most recent month so the panel has numbers right away.
            [$from, $to] = [$end->subDays(self::WINDOW_DAYS - 1), $end];
        } elseif (CarbonImmutable::parse($last)->lt($end->subDays(self::REFRESH_DAYS))) {
            [$from, $to] = [CarbonImmutable::parse($last)->subDays(self::REFRESH_DAYS - 1), $end];
        } elseif (CarbonImmutable::parse($first)->gt($oldest) && $project->gsc_synced_at?->isToday()) {
            // Up to date for today: walk back through the history one window at a time.
            $to = CarbonImmutable::parse($first)->subDay();
            [$from, $to] = [$to->subDays(self::WINDOW_DAYS - 1)->max($oldest), $to];
        } else {
            [$from, $to] = [$end->subDays(self::REFRESH_DAYS - 1), $end];
        }

        $stored = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $rows = GoogleClient::searchAnalytics($project->gsc_property, [
                'startDate' => $from->toDateString(),
                'endDate' => $to->toDateString(),
                'dimensions' => ['date', 'page'],
                'rowLimit' => self::ROW_LIMIT,
                'startRow' => $page * self::ROW_LIMIT,
                'dataState' => 'all',
            ]);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('gsc_page_days')->upsert(array_map(fn ($row) => [
                    'project_id' => $project->id,
                    'day' => $row['keys'][0],
                    'page' => $row['keys'][1],
                    'page_hash' => UrlNormalizer::hash($row['keys'][1]),
                    'clicks' => (int) $row['clicks'],
                    'impressions' => (int) $row['impressions'],
                    'position' => round((float) $row['position'], 2),
                ], $chunk), ['project_id', 'day', 'page_hash'], ['clicks', 'impressions', 'position']);
            }
            $stored += count($rows);

            if (count($rows) < self::ROW_LIMIT) {
                break;
            }
        }

        $project->forceFill(['gsc_synced_at' => now()])->save();

        return $stored;
    }

    /**
     * Replaces the project's page × query totals for the latest 28 days (once a day).
     *
     * @return int rows stored
     */
    public static function syncQueries(Project $project): int
    {
        $end = CarbonImmutable::today()->subDays(self::LAG_DAYS);
        $rows = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = GoogleClient::searchAnalytics($project->gsc_property, [
                'startDate' => $end->subDays(27)->toDateString(),
                'endDate' => $end->toDateString(),
                'dimensions' => ['page', 'query'],
                'rowLimit' => self::ROW_LIMIT,
                'startRow' => $page * self::ROW_LIMIT,
                'dataState' => 'all',
            ]);
            array_push($rows, ...$batch);
            if (count($batch) < self::ROW_LIMIT) {
                break;
            }
        }

        DB::transaction(function () use ($project, $rows) {
            DB::table('gsc_queries')->where('project_id', $project->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('gsc_queries')->insert(array_map(fn ($row) => [
                    'project_id' => $project->id,
                    'page_hash' => UrlNormalizer::hash($row['keys'][0]),
                    'page' => $row['keys'][0],
                    'query' => mb_substr($row['keys'][1], 0, 500),
                    'clicks' => (int) $row['clicks'],
                    'impressions' => (int) $row['impressions'],
                    'position' => round((float) $row['position'], 2),
                ], $chunk));
            }
        });
        $project->forceFill(['gsc_queries_synced_on' => now()->toDateString()])->save();

        return count($rows);
    }

    /**
     * Totals per page over the latest 28 days of data, for joining onto crawl pages by URL hash.
     */
    public static function last28(int $projectId): Builder
    {
        $latest = DB::table('gsc_page_days')->where('project_id', $projectId)->max('day');
        $from = $latest ? CarbonImmutable::parse($latest)->subDays(27)->toDateString() : '9999-12-31';

        return DB::table('gsc_page_days')
            ->where('project_id', $projectId)
            ->where('day', '>=', $from)
            ->groupBy('page_hash')
            ->selectRaw('page_hash, SUM(clicks) as gsc_clicks, SUM(impressions) as gsc_impressions, SUM(position * impressions) / NULLIF(SUM(impressions), 0) as gsc_position');
    }
}
