<?php

namespace App\Segments;

use App\Google\GscSync;
use App\Models\Crawl;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Splits a project's latest crawl into groups of pages (by first URL folder, WordPress content type and category,
 * click depth and number of internal links)
 * and shows each group's size, problems and Search Console traffic (docs/09 Faz 3, Oncrawl/JetOctopus idea).
 */
class Segments
{
    public const ROOT = '/ (ana dizin)';

    /** Upper bound of each internal link band; the last band is open-ended. */
    public const LINK_BANDS = [1 => '0-1', 5 => '2-5', 10 => '6-10', 25 => '11-25', 50 => '26-50', PHP_INT_MAX => '51+'];

    public const TYPES = [
        'home' => 'Ana sayfa', 'blog' => 'Blog ana sayfası', 'post' => 'Yazı', 'page' => 'Sayfa', 'product' => 'Ürün',
        'category' => 'Kategori arşivi', 'tag' => 'Etiket arşivi', 'author' => 'Yazar arşivi', 'date' => 'Tarih arşivi',
        'attachment' => 'Ek sayfası', 'search' => 'Arama sonucu', '404' => '404 sayfası',
    ];

    public function __construct(private Project $project) {}

    public function crawl(): ?Crawl
    {
        return Crawl::query()->where('project_id', $this->project->id)->where('status', 'completed')->latest('id')->first();
    }

    /**
     * @return array{folders: Collection, types: Collection, categories: Collection, depths: Collection, links: Collection, gsc: bool}
     */
    public function all(): array
    {
        $crawl = $this->crawl();
        $gsc = filled($this->project->gsc_property);
        if (! $crawl) {
            return ['folders' => collect(), 'types' => collect(), 'categories' => collect(), 'depths' => collect(), 'links' => collect(), 'gsc' => $gsc];
        }

        $issues = DB::table('crawl_issues')->where('crawl_id', $crawl->id)->whereIn('severity', ['high', 'medium'])
            ->groupBy('page_id')->selectRaw('page_id, MAX(CASE WHEN severity = \'high\' THEN 1 ELSE 0 END) as has_high, COUNT(*) as n')
            ->get()->keyBy('page_id');

        $columns = ['crawl_pages.id', 'crawl_pages.url', 'crawl_pages.depth', 'crawl_pages.inlinks', 'crawl_pages.wp_type', 'crawl_pages.wp_category'];
        $query = DB::table('crawl_pages')->where('crawl_pages.crawl_id', $crawl->id)->where('crawl_pages.state', 'done')
            ->where('crawl_pages.status_code', 200)->where('crawl_pages.content_type', 'like', '%html%');
        if ($gsc) {
            $query->leftJoinSub(GscSync::last28($this->project->id), 'gsc', 'gsc.page_hash', '=', 'crawl_pages.url_hash')
                ->select([...$columns, 'gsc.gsc_clicks', 'gsc.gsc_impressions', 'gsc.gsc_position']);
        } else {
            $query->select($columns);
        }

        $folders = $types = $categories = $depths = $links = [];
        $add = function (array &$bucket, string|int $key, object $page, ?object $issue): void {
            $row = $bucket[$key] ?? ['segment' => $key, 'pages' => 0, 'clean' => 0, 'high' => 0, 'issues' => 0, 'inlinks' => 0, 'clicks' => 0, 'impressions' => 0, 'position_weight' => 0.0];
            $row['pages']++;
            $row['clean'] += $issue ? 0 : 1;
            $row['high'] += $issue && $issue->has_high ? 1 : 0;
            $row['issues'] += $issue->n ?? 0;
            $row['inlinks'] += (int) $page->inlinks;
            $row['clicks'] += (int) ($page->gsc_clicks ?? 0);
            $row['impressions'] += (int) ($page->gsc_impressions ?? 0);
            $row['position_weight'] += (float) ($page->gsc_position ?? 0) * (int) ($page->gsc_impressions ?? 0);
            $bucket[$key] = $row;
        };
        foreach ($query->orderBy('crawl_pages.id')->cursor() as $page) {
            $issue = $issues[$page->id] ?? null;
            $add($folders, self::folder($page->url), $page, $issue);
            $add($depths, min((int) $page->depth, 5), $page, $issue);
            $add($links, self::linkBand((int) $page->inlinks), $page, $issue);
            if ($page->wp_type !== null) {
                $add($types, self::typeLabel($page->wp_type), $page, $issue);
            }
            if ($page->wp_category !== null) {
                $add($categories, $page->wp_category, $page, $issue);
            }
        }

        $finish = fn (array $row) => $row + [
            'health' => (int) round($row['clean'] / $row['pages'] * 100),
            'avg_inlinks' => round($row['inlinks'] / $row['pages'], 1),
            'avg_clicks' => round($row['clicks'] / $row['pages'], 1),
            'position' => $row['impressions'] > 0 ? round($row['position_weight'] / $row['impressions'], 1) : null,
        ];
        $bandOrder = array_flip(array_values(self::LINK_BANDS));

        return [
            'folders' => collect($folders)->map($finish)->sortByDesc('pages')->values(),
            'types' => collect($types)->map($finish)->sortByDesc('pages')->values(),
            'categories' => collect($categories)->map($finish)->sortByDesc('pages')->values(),
            'depths' => collect($depths)->map($finish)->sortKeys()->values(),
            'links' => collect($links)->map($finish)->sortBy(fn ($row) => $bandOrder[$row['segment']])->values(),
            'gsc' => $gsc,
        ];
    }

    public static function linkBand(int $inlinks): string
    {
        foreach (self::LINK_BANDS as $max => $label) {
            if ($inlinks <= $max) {
                return $label;
            }
        }

        return '51+';
    }

    /** Turkish name of a WordPress content type: post → Yazı, archive:portfolio → "portfolio arşivi", other custom types as they are. */
    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? match (true) {
            str_starts_with($type, 'archive:') => substr($type, 8).' arşivi',
            str_starts_with($type, 'tax:') => substr($type, 4).' arşivi',
            default => $type,
        };
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
