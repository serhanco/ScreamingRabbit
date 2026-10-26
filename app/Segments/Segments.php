<?php

namespace App\Segments;

use App\Google\GscSync;
use App\Models\Crawl;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Splits a project's latest crawl into groups of pages (by first URL folder, and by click depth)
 * and shows each group's size, problems and Search Console traffic (docs/09 Faz 3, Oncrawl/JetOctopus idea).
 */
class Segments
{
    public const ROOT = '/ (ana dizin)';

    public function __construct(private Project $project) {}

    public function crawl(): ?Crawl
    {
        return Crawl::query()->where('project_id', $this->project->id)->where('status', 'completed')->latest('id')->first();
    }

    /**
     * @return array{folders: Collection, depths: Collection, gsc: bool}
     */
    public function all(): array
    {
        $crawl = $this->crawl();
        $gsc = filled($this->project->gsc_property);
        if (! $crawl) {
            return ['folders' => collect(), 'depths' => collect(), 'gsc' => $gsc];
        }

        $issues = DB::table('crawl_issues')->where('crawl_id', $crawl->id)->whereIn('severity', ['high', 'medium'])
            ->groupBy('page_id')->selectRaw('page_id, MAX(CASE WHEN severity = \'high\' THEN 1 ELSE 0 END) as has_high, COUNT(*) as n')
            ->get()->keyBy('page_id');

        $query = DB::table('crawl_pages')->where('crawl_pages.crawl_id', $crawl->id)->where('crawl_pages.state', 'done')
            ->where('crawl_pages.status_code', 200)->where('crawl_pages.content_type', 'like', '%html%');
        if ($gsc) {
            $query->leftJoinSub(GscSync::last28($this->project->id), 'gsc', 'gsc.page_hash', '=', 'crawl_pages.url_hash')
                ->select('crawl_pages.id', 'crawl_pages.url', 'crawl_pages.depth', 'crawl_pages.inlinks', 'gsc.gsc_clicks', 'gsc.gsc_impressions');
        } else {
            $query->select('crawl_pages.id', 'crawl_pages.url', 'crawl_pages.depth', 'crawl_pages.inlinks');
        }

        $folders = [];
        $depths = [];
        $add = function (array &$bucket, string|int $key, object $page, ?object $issue): void {
            $row = $bucket[$key] ?? ['segment' => $key, 'pages' => 0, 'clean' => 0, 'high' => 0, 'issues' => 0, 'inlinks' => 0, 'clicks' => 0, 'impressions' => 0];
            $row['pages']++;
            $row['clean'] += $issue ? 0 : 1;
            $row['high'] += $issue && $issue->has_high ? 1 : 0;
            $row['issues'] += $issue->n ?? 0;
            $row['inlinks'] += (int) $page->inlinks;
            $row['clicks'] += (int) ($page->gsc_clicks ?? 0);
            $row['impressions'] += (int) ($page->gsc_impressions ?? 0);
            $bucket[$key] = $row;
        };
        foreach ($query->orderBy('crawl_pages.id')->cursor() as $page) {
            $issue = $issues[$page->id] ?? null;
            $add($folders, self::folder($page->url), $page, $issue);
            $add($depths, min((int) $page->depth, 5), $page, $issue);
        }

        $finish = fn (array $row) => $row + [
            'health' => (int) round($row['clean'] / $row['pages'] * 100),
            'avg_inlinks' => round($row['inlinks'] / $row['pages'], 1),
            'avg_clicks' => round($row['clicks'] / $row['pages'], 1),
        ];

        return [
            'folders' => collect($folders)->map($finish)->sortByDesc('pages')->values(),
            'depths' => collect($depths)->map($finish)->sortKeys()->values(),
            'gsc' => $gsc,
        ];
    }

    /**
     * First path folder: https://x.com/blog/post-1 → /blog/; the home page and top-level pages share the root segment.
     */
    public static function folder(string $url): string
    {
        $parts = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

        return count($parts) > 1 ? '/'.$parts[0].'/' : self::ROOT;
    }
}
