<?php

namespace App\Crawler;

use App\Audit\Auditor;
use App\Jobs\CrawlBatch;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Crawls a project in time-boxed batches so it fits the short job windows of shared hosting.
 * Every call to run() does as much work as fits the budget and reports whether more remains.
 */
class CrawlRunner
{
    private const TIMEOUT_SECONDS = 15;

    private const MAX_BODY_BYTES = 2_000_000;

    private array $settings;

    private ?RobotsTxt $robots = null;

    private int $pageCount = 0;

    public function __construct(private Crawl $crawl)
    {
        $this->settings = $crawl->settings ?? [];
    }

    public static function start(Project $project): Crawl
    {
        $crawl = Crawl::create([
            'project_id' => $project->id,
            'status' => 'pending',
            'settings' => $project->effectiveCrawlSettings(),
        ]);

        CrawlBatch::dispatch($crawl->id);

        return $crawl;
    }

    /**
     * @return bool true when work remains and another batch job should follow
     */
    public function run(float $budgetSeconds = 35): bool
    {
        $deadline = microtime(true) + $budgetSeconds;

        if ($this->crawl->status === 'pending') {
            $this->seed();
        }

        $this->robots = new RobotsTxt((string) $this->crawl->robots_txt, 'screamingrabbit');
        $this->pageCount = $this->crawl->pages()->count();

        while (microtime(true) < $deadline) {
            if ($this->crawl->fresh()->status === 'cancelled') {
                return false;
            }

            $pages = $this->crawl->pages()
                ->where('state', 'queued')
                ->orderBy('depth')->orderBy('id')
                ->limit(max(1, (int) $this->settings['concurrency']))
                ->get();

            if ($pages->isEmpty()) {
                $this->finish();

                return false;
            }

            $this->fetchBatch($pages);
            usleep(max(0, (int) $this->settings['delay_ms']) * 1000);
        }

        return true;
    }

    private function seed(): void
    {
        $project = $this->crawl->project;
        $start = UrlNormalizer::resolve($project->base_url, $project->base_url);
        $origin = parse_url($start, PHP_URL_SCHEME).'://'.parse_url($start, PHP_URL_HOST);

        $this->crawl->update(['status' => 'running', 'started_at' => now()]);
        $this->pageCount = 0;

        $robotsBody = $this->fetchText($origin.'/robots.txt');
        $this->crawl->robots_txt = $robotsBody;
        $robots = new RobotsTxt((string) $robotsBody, 'screamingrabbit');

        $this->addPage($start, 0);

        $candidates = $robots->sitemaps ?: [$origin.'/sitemap.xml', $origin.'/sitemap_index.xml', $origin.'/wp-sitemap.xml'];
        $limit = (int) $this->settings['max_urls'];

        foreach ($candidates as $sitemap) {
            $urls = SitemapReader::read($sitemap, fn (string $url) => $this->fetchText($url), $limit);
            if ($urls === []) {
                continue;
            }
            $this->crawl->sitemap_found = true;
            foreach ($urls as $url) {
                $resolved = UrlNormalizer::resolve($url, $start);
                if ($resolved && UrlNormalizer::isInternal($resolved, $start) && ! UrlNormalizer::isAsset($resolved)) {
                    $this->addPage($resolved, 1, true);
                }
            }
            break;
        }

        $this->crawl->save();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CrawlPage>  $pages
     */
    private function fetchBatch($pages): void
    {
        $toFetch = collect();

        foreach ($pages as $page) {
            if (($this->settings['respect_robots'] ?? true) && ! $this->robots->isAllowed($page->url)) {
                $page->update(['state' => 'blocked']);
            } else {
                $toFetch->push($page);
            }
        }

        if ($toFetch->isEmpty()) {
            return;
        }

        $started = microtime(true);
        $responses = Http::pool(function (Pool $pool) use ($toFetch) {
            return $toFetch->map(fn (CrawlPage $page) => $pool->as((string) $page->id)
                ->withUserAgent($this->settings['user_agent'])
                ->timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->get($page->url))->all();
        });
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        foreach ($toFetch as $page) {
            $response = $responses[(string) $page->id] ?? null;

            if ($response instanceof Response) {
                $this->handleResponse($page, $response, $elapsed);
            } else {
                $message = $response instanceof ConnectionException ? $response->getMessage() : 'Yanıt alınamadı';
                $page->update(['state' => 'done', 'error' => mb_substr($message, 0, 250), 'fetched_at' => now(), 'response_ms' => $elapsed]);
            }
        }
    }

    private function handleResponse(CrawlPage $page, Response $response, int $elapsed): void
    {
        $status = $response->status();
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        $update = [
            'state' => 'done',
            'status_code' => $status,
            'content_type' => $contentType ?: null,
            'x_robots' => $response->header('X-Robots-Tag') ?: null,
            'response_ms' => $elapsed,
            'fetched_at' => now(),
        ];

        if ($status >= 300 && $status < 400) {
            $target = UrlNormalizer::resolve((string) $response->header('Location'), $page->url);
            $update['redirect_to'] = $target;
            if ($target && UrlNormalizer::isInternal($target, $page->url) && ! UrlNormalizer::isAsset($target)) {
                $this->addPage($target, $page->depth + 1);
            }
        } elseif ($status === 200 && str_contains($contentType, 'html')) {
            $body = mb_strcut($response->body(), 0, self::MAX_BODY_BYTES);
            $update['size_bytes'] = strlen($body);
            $parsed = HtmlParser::parse($body, $page->url);

            $update += [
                'title' => $parsed['title'],
                'meta_description' => $parsed['description'],
                'meta_robots' => $parsed['meta_robots'],
                'canonical' => $parsed['canonical'],
                'h1_count' => count($parsed['h1']),
                'h1' => $parsed['h1'][0] ?? null,
                'word_count' => $parsed['word_count'],
                'content_hash' => $parsed['content_hash'],
                'wordpress_hint' => $parsed['wordpress_hint'],
                'images_missing_alt' => $parsed['images_missing_alt'],
                'mixed_content' => $parsed['mixed_content'],
                'meta_refresh' => $parsed['meta_refresh'] ? mb_substr($parsed['meta_refresh'], 0, 250) : null,
                'page_type' => $parsed['page_type'],
                'seo_plugin' => $parsed['seo_plugin'],
            ];

            $update['http_internal_links'] = $this->storeLinks($page, $parsed['links']);
        }

        $page->update($update);
    }

    /**
     * @param  array<int, array{url: string, nofollow: bool}>  $links
     */
    private function storeLinks(CrawlPage $page, array $links): int
    {
        $rows = [];
        $seen = [];
        $httpLinks = 0;

        foreach ($links as $link) {
            $url = $link['url'];
            if (isset($seen[$url]) || ! UrlNormalizer::isInternal($url, $page->url) || UrlNormalizer::isAsset($url)) {
                continue;
            }
            $seen[$url] = true;

            if (str_starts_with($page->url, 'https://') && str_starts_with($url, 'http://')) {
                $httpLinks++;
            }

            $rows[] = [
                'crawl_id' => $this->crawl->id,
                'from_page_id' => $page->id,
                'to_hash' => UrlNormalizer::hash($url),
                'nofollow' => $link['nofollow'],
            ];

            if (! $link['nofollow']) {
                $this->addPage($url, $page->depth + 1);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('crawl_links')->insert($chunk);
        }

        return $httpLinks;
    }

    private function addPage(string $url, int $depth, bool $inSitemap = false): void
    {
        $hash = UrlNormalizer::hash($url);

        if ($this->pageCount >= (int) $this->settings['max_urls']) {
            if ($inSitemap) {
                CrawlPage::where('crawl_id', $this->crawl->id)->where('url_hash', $hash)->update(['in_sitemap' => true]);
            }

            return;
        }

        $inserted = DB::table('crawl_pages')->insertOrIgnore([
            'crawl_id' => $this->crawl->id,
            'url' => $url,
            'url_hash' => $hash,
            'depth' => $depth,
            'in_sitemap' => $inSitemap,
            'state' => 'queued',
        ]);

        if ($inserted) {
            $this->pageCount++;
        } elseif ($inSitemap) {
            CrawlPage::where('crawl_id', $this->crawl->id)->where('url_hash', $hash)->update(['in_sitemap' => true]);
        }
    }

    private function finish(): void
    {
        DB::statement(
            'UPDATE crawl_pages SET inlinks = (SELECT COUNT(*) FROM crawl_links WHERE crawl_links.crawl_id = crawl_pages.crawl_id AND crawl_links.to_hash = crawl_pages.url_hash) WHERE crawl_id = ?',
            [$this->crawl->id],
        );

        $this->crawl->detected_platform = $this->crawl->pages()->where('wordpress_hint', true)->exists() ? 'wordpress' : null;
        $this->crawl->save();

        (new Auditor)->run($this->crawl);

        $this->crawl->update(['status' => 'completed', 'finished_at' => now()]);
    }

    private function fetchText(string $url): ?string
    {
        try {
            $response = Http::withUserAgent($this->settings['user_agent'])->timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }
}
