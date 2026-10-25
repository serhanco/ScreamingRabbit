<?php

namespace App\Google;

use App\Models\Crawl;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns the synced Search Console data into lists of things to act on (docs/04 §4.2).
 */
class GscInsights
{
    private const LIMIT = 50;

    /**
     * Typical organic CTR by position, used to spot titles/descriptions that under-perform for their rank.
     * Rounded from public CTR studies (Advanced Web Ranking / Backlinko, 2024-2025).
     */
    public const EXPECTED_CTR = [1 => 0.28, 2 => 0.15, 3 => 0.11, 4 => 0.08, 5 => 0.07, 6 => 0.05, 7 => 0.04, 8 => 0.03, 9 => 0.03, 10 => 0.025];

    private const MIN_IMPRESSIONS = 100;

    public function __construct(private Project $project) {}

    /**
     * @return array<string, Collection<int, object>>
     */
    public function all(): array
    {
        return [
            'low_ctr' => $this->lowCtr(),
            'opportunities' => $this->opportunities(),
            'cannibalization' => $this->cannibalization(),
            'declining' => $this->declining(),
            'orphans' => $this->orphans(),
            'zombies' => $this->zombies(),
            'index' => $this->indexIssues(),
        ];
    }

    /** Ranked on page one but clicked far less than pages at that position usually are. */
    public function lowCtr(): Collection
    {
        return GscSync::last28($this->project->id)
            ->addSelect(DB::raw('MIN(page) as page'))
            ->havingRaw('SUM(impressions) >= ?', [self::MIN_IMPRESSIONS])
            ->get()
            ->map(function ($row) {
                $row->ctr = $row->gsc_clicks / $row->gsc_impressions;
                $row->expected = self::EXPECTED_CTR[max(1, (int) round($row->gsc_position))] ?? null;

                return $row;
            })
            ->filter(fn ($row) => $row->expected !== null && $row->ctr < $row->expected / 2)
            ->sortByDesc('gsc_impressions')->take(self::LIMIT)->values();
    }

    /** Queries just off page one or low on it, with enough impressions to be worth pushing up. */
    public function opportunities(): Collection
    {
        return DB::table('gsc_queries')->where('project_id', $this->project->id)
            ->whereBetween('position', [8, 20])->where('impressions', '>=', 50)
            ->orderByDesc('impressions')->limit(self::LIMIT)
            ->get(['query', 'page', 'clicks', 'impressions', 'position']);
    }

    /** One query, several of the site's pages competing for it. */
    public function cannibalization(): Collection
    {
        $queries = DB::table('gsc_queries')->where('project_id', $this->project->id)->where('impressions', '>=', 20)
            ->groupBy('query')->havingRaw('COUNT(*) >= 2')
            ->orderByRaw('SUM(impressions) DESC')->limit(self::LIMIT)
            ->selectRaw('query, SUM(impressions) as impressions, COUNT(*) as pages')->get();

        $pages = DB::table('gsc_queries')->where('project_id', $this->project->id)->where('impressions', '>=', 20)
            ->whereIn('query', $queries->pluck('query'))->orderByDesc('impressions')
            ->get(['query', 'page', 'clicks', 'impressions', 'position'])->groupBy('query');

        return $queries->map(function ($row) use ($pages) {
            $row->urls = $pages[$row->query] ?? collect();

            return $row;
        });
    }

    /** Pages that lost at least 30% of their clicks compared with the 28 days before. */
    public function declining(): Collection
    {
        $latest = DB::table('gsc_page_days')->where('project_id', $this->project->id)->max('day');
        if ($latest === null) {
            return collect();
        }
        $end = CarbonImmutable::parse($latest);

        return DB::table('gsc_page_days')->where('project_id', $this->project->id)
            ->where('day', '>', $end->subDays(56)->toDateString())
            ->groupBy('page_hash')
            ->selectRaw('MIN(page) as page,
                SUM(CASE WHEN day > ? THEN clicks ELSE 0 END) as now_clicks,
                SUM(CASE WHEN day <= ? THEN clicks ELSE 0 END) as before_clicks', [$end->subDays(28)->toDateString(), $end->subDays(28)->toDateString()])
            ->get()
            ->filter(fn ($row) => $row->before_clicks >= 10 && $row->now_clicks <= $row->before_clicks * 0.7)
            ->sortByDesc(fn ($row) => $row->before_clicks - $row->now_clicks)->take(self::LIMIT)->values();
    }

    /** Google shows these pages, but the crawl found no internal link to them. */
    public function orphans(): Collection
    {
        $crawl = $this->latestCrawl();
        if ($crawl === null) {
            return collect();
        }
        $linked = $crawl->pages()->where(fn ($q) => $q->where('inlinks', '>', 0)->orWhere('depth', 0))->pluck('url_hash')->flip();

        return GscSync::last28($this->project->id)->addSelect(DB::raw('MIN(page) as page'))->having('gsc_impressions', '>', 0)
            ->orderByDesc('gsc_impressions')->get()
            ->reject(fn ($row) => isset($linked[$row->page_hash]))
            ->take(self::LIMIT)->values();
    }

    /** Indexable pages without a single impression in 90 days; only once there are 90 days of data. */
    public function zombies(): Collection
    {
        $crawl = $this->latestCrawl();
        $first = DB::table('gsc_page_days')->where('project_id', $this->project->id)->min('day');
        if ($crawl === null || $first === null || CarbonImmutable::parse($first)->gt(now()->subDays(92))) {
            return collect();
        }
        $seen = DB::table('gsc_page_days')->where('project_id', $this->project->id)->where('day', '>=', now()->subDays(92)->toDateString())
            ->where('impressions', '>', 0)->distinct()->pluck('page_hash')->flip();

        return $crawl->pages()->where('status_code', 200)->where('content_type', 'like', '%html%')
            ->where(fn ($q) => $q->whereNull('meta_robots')->orWhere('meta_robots', 'not like', '%noindex%'))
            ->orderBy('depth')->get(['url', 'url_hash', 'inlinks', 'word_count'])
            ->reject(fn ($page) => isset($seen[$page->url_hash]))
            ->take(self::LIMIT)->values();
    }

    /** URL Inspection: not indexed, or Google picked another canonical than the page declares. */
    public function indexIssues(): Collection
    {
        return DB::table('gsc_inspections')->where('project_id', $this->project->id)->whereNull('error')
            ->where(fn ($q) => $q->where('verdict', '!=', 'PASS')
                ->orWhere(fn ($q) => $q->whereNotNull('google_canonical')->whereNotNull('user_canonical')->whereColumn('google_canonical', '!=', 'user_canonical')))
            ->orderBy('id')->limit(self::LIMIT)
            ->get(['url', 'verdict', 'coverage_state', 'google_canonical', 'user_canonical']);
    }

    private function latestCrawl(): ?Crawl
    {
        return Crawl::query()->where('project_id', $this->project->id)->where('status', 'completed')->latest('id')->first();
    }
}
