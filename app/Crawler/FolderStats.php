<?php

namespace App\Crawler;

use App\Models\Crawl;

/**
 * Pages grouped by their first URL folder (/blog/, /urun/ …): how many there are, how deep they sit, how many can be
 * indexed and how many fail, so a section buried too deep or full of errors stands out.
 */
class FolderStats
{
    public const MAX_ROWS = 30;

    /**
     * @return array<int, array{folder: string, pages: int, indexable: int, errors: int, avg_depth: float, max_depth: int, avg_link_score: ?float, issues: int}>
     */
    public static function for(Crawl $crawl): array
    {
        $issues = $crawl->issues()->whereNotNull('page_id')->selectRaw('page_id, count(*) as total')->groupBy('page_id')->pluck('total', 'page_id');

        $rows = [];
        $query = $crawl->pages()->where('state', 'done')
            ->select(['id', 'url', 'depth', 'status_code', 'content_type', 'state', 'meta_robots', 'x_robots', 'canonical', 'link_score']);
        foreach ($query->lazyById(1000) as $page) {
            $folder = self::folder($page->url);
            $row = $rows[$folder] ?? ['folder' => $folder, 'pages' => 0, 'indexable' => 0, 'errors' => 0, 'depth_sum' => 0, 'max_depth' => 0, 'score_sum' => 0, 'scored' => 0, 'issues' => 0];
            $row['pages']++;
            $row['indexable'] += $page->isIndexable() ? 1 : 0;
            $row['errors'] += ($page->status_code === null || $page->status_code >= 400) ? 1 : 0;
            $row['depth_sum'] += $page->depth;
            $row['max_depth'] = max($row['max_depth'], $page->depth);
            if ($page->link_score !== null) {
                $row['score_sum'] += $page->link_score;
                $row['scored']++;
            }
            $row['issues'] += (int) ($issues[$page->id] ?? 0);
            $rows[$folder] = $row;
        }

        return collect($rows)
            ->sortByDesc('pages')
            ->take(self::MAX_ROWS)
            ->map(fn (array $row) => [
                'folder' => $row['folder'],
                'pages' => $row['pages'],
                'indexable' => $row['indexable'],
                'errors' => $row['errors'],
                'avg_depth' => round($row['depth_sum'] / $row['pages'], 1),
                'max_depth' => $row['max_depth'],
                'avg_link_score' => $row['scored'] > 0 ? round($row['score_sum'] / $row['scored'], 1) : null,
                'issues' => $row['issues'],
            ])
            ->values()
            ->all();
    }

    /** "/blog/" for https://example.com/blog/a/b, "/" for the home page and pages directly under it. */
    public static function folder(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), fn (string $s) => $s !== ''));
        $isFile = ! str_ends_with($path, '/');

        if ($segments === [] || (count($segments) === 1 && $isFile)) {
            return '/';
        }

        return '/'.rawurldecode($segments[0]).'/';
    }
}
