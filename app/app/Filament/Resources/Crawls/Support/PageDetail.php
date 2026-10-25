<?php

namespace App\Filament\Resources\Crawls\Support;

use App\Audit\RuleCatalog;
use App\Models\CrawlPage;
use Illuminate\Support\Facades\DB;

/**
 * Collects what the per-URL detail modal shows: findings, incoming and outgoing links.
 */
class PageDetail
{
    private const LINK_LIMIT = 100;

    private const QUERY_LIMIT = 20;

    /**
     * @return array{page: CrawlPage, findings: array<int, array<string, string>>, inlinks: \Illuminate\Support\Collection, outlinks: \Illuminate\Support\Collection, queries: \Illuminate\Support\Collection, inspection: ?object}
     */
    public static function for(CrawlPage $page): array
    {
        $catalog = RuleCatalog::all();

        $findings = $page->issues()->get()->map(function ($issue) use ($catalog) {
            $rule = $catalog[$issue->rule] ?? ['title' => $issue->rule, 'fix' => ''];

            return [
                'title' => $rule['title'],
                'severity' => RuleCatalog::SEVERITIES[$issue->severity] ?? $issue->severity,
                'detail' => (string) $issue->detail,
                'fix' => $rule['fix'],
            ];
        })->values()->all();

        $inlinks = $page->crawl->pages()
            ->join('crawl_links', 'crawl_links.from_page_id', '=', 'crawl_pages.id')
            ->where('crawl_links.to_hash', $page->url_hash)
            ->distinct()
            ->limit(self::LINK_LIMIT)
            ->pluck('crawl_pages.url');

        $outlinks = $page->crawl->pages()
            ->join('crawl_links', 'crawl_links.to_hash', '=', 'crawl_pages.url_hash')
            ->where('crawl_links.from_page_id', $page->id)
            ->distinct()
            ->limit(self::LINK_LIMIT)
            ->pluck('crawl_pages.url');

        // Search Console queries this URL was shown for in the latest 28 days, most clicked first.
        $queries = DB::table('gsc_queries')
            ->where('project_id', $page->crawl->project_id)
            ->where('page_hash', $page->url_hash)
            ->orderByDesc('clicks')->orderByDesc('impressions')
            ->limit(self::QUERY_LIMIT)
            ->get(['query', 'clicks', 'impressions', 'position']);

        $inspection = DB::table('gsc_inspections')
            ->where('project_id', $page->crawl->project_id)
            ->where('page_hash', $page->url_hash)
            ->first();

        return compact('page', 'findings', 'inlinks', 'outlinks', 'queries', 'inspection');
    }
}
