<?php

namespace App\Google;

use App\Models\Crawl;
use App\Models\GoogleConnection;
use App\Models\Project;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

/**
 * Asks Google how it sees the most important pages (indexed or not, Google's canonical, last crawl), a few per run.
 * Pages with the most clicks go first, then the shallowest; each page is re-checked after a week.
 */
class UrlInspector
{
    private const PER_RUN = 20;

    /** Well under Google's 2,000 per property per day, leaving room for Search Console's own tools. */
    private const PER_DAY = 500;

    /** One Google connection may serve several projects; this keeps its daily total modest as well. */
    public const PER_CONNECTION_DAY = 1000;

    private const RECHECK_DAYS = 7;

    /**
     * @return int pages inspected
     */
    public static function run(Project $project, ?GoogleConnection $connection = null): int
    {
        $connection ??= $project->gscConnection();
        if ($connection === null) {
            return 0;
        }

        $crawl = Crawl::query()->where('project_id', $project->id)->where('status', 'completed')->latest('id')->first();
        $today = DB::table('gsc_inspections')->where('project_id', $project->id)->where('inspected_at', '>=', now()->startOfDay())->count();
        if ($crawl === null || $today >= self::PER_DAY || $connection->inspectionsToday() >= self::PER_CONNECTION_DAY) {
            return 0;
        }

        $pages = $crawl->pages()
            ->leftJoinSub(GscSync::last28($project->id), 'gsc', 'gsc.page_hash', '=', 'crawl_pages.url_hash')
            ->leftJoinSub(DB::table('gsc_inspections')->where('project_id', $project->id)->select('page_hash', 'inspected_at'), 'gi', 'gi.page_hash', '=', 'crawl_pages.url_hash')
            ->where('crawl_pages.status_code', 200)
            ->where('crawl_pages.content_type', 'like', '%html%')
            ->where(fn ($q) => $q->whereNull('gi.inspected_at')->orWhere('gi.inspected_at', '<', now()->subDays(self::RECHECK_DAYS)))
            ->orderByRaw('COALESCE(gsc.gsc_clicks, 0) DESC')
            ->orderBy('crawl_pages.depth')
            ->orderBy('crawl_pages.id')
            ->limit(min(self::PER_RUN, self::PER_DAY - $today, self::PER_CONNECTION_DAY - $connection->inspectionsToday()))
            ->get(['crawl_pages.url', 'crawl_pages.url_hash']);

        foreach ($pages as $page) {
            $row = ['url' => $page->url, 'inspected_at' => now(), 'error' => null];
            try {
                $result = GoogleClient::inspectUrl($connection, $project->gsc_property, $page->url);
                $row += [
                    'verdict' => $result['verdict'] ?? null,
                    'coverage_state' => isset($result['coverageState']) ? mb_substr($result['coverageState'], 0, 250) : null,
                    'indexing_state' => $result['indexingState'] ?? null,
                    'google_canonical' => $result['googleCanonical'] ?? null,
                    'user_canonical' => $result['userCanonical'] ?? null,
                    'last_crawl_time' => isset($result['lastCrawlTime']) ? \Carbon\Carbon::parse($result['lastCrawlTime']) : null,
                ];
            } catch (RequestException $e) {
                if ($e->response->status() === 429) {
                    break;
                }
                $row['error'] = mb_substr($e->response->json('error.message') ?? $e->getMessage(), 0, 250);
            }

            DB::table('gsc_inspections')->updateOrInsert(['project_id' => $project->id, 'page_hash' => $page->url_hash], $row);
        }

        return $pages->count();
    }
}
