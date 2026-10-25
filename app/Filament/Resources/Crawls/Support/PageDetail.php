<?php

namespace App\Filament\Resources\Crawls\Support;

use App\Audit\RuleCatalog;
use App\Models\CrawlPage;

/**
 * Collects what the per-URL detail modal shows: findings, incoming and outgoing links.
 */
class PageDetail
{
    private const LINK_LIMIT = 100;

    /**
     * @return array{page: CrawlPage, findings: array<int, array<string, string>>, inlinks: \Illuminate\Support\Collection, outlinks: \Illuminate\Support\Collection}
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

        return compact('page', 'findings', 'inlinks', 'outlinks');
    }
}
