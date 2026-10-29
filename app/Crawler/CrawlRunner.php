<?php

namespace App\Crawler;

use App\Audit\Auditor;
use App\Audit\SecurityHeaders;
use App\Jobs\CrawlBatch;
use App\Models\Crawl;
use App\Models\CrawlImage;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Performance\PageSpeed;
use App\Tasks\TaskBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
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

    private const MAX_IMAGES_PER_PAGE = 100;

    private const BOT_TIMEOUT_SECONDS = 8;

    private const MAX_EXTERNAL_PER_PAGE = 100;

    private array $settings;

    private ?UrlFilter $filter = null;

    private ?RobotsTxt $robots = null;

    private int $pageCount = 0;

    /** @var array{user: ?string, password: ?string, headers: array<string, string>}|null */
    private ?array $access = null;

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

        // A robots.txt typed in the project settings replaces the live one for this crawl, to try a change before publishing it.
        $this->robots = new RobotsTxt((string) (filled($this->settings['robots_override'] ?? null) ? $this->settings['robots_override'] : $this->crawl->robots_txt), 'screamingrabbit');
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
                // Pages are done; check the images they use, then finish.
                $images = $this->crawl->images()->where('state', 'queued')->orderBy('id')
                    ->limit(max(1, (int) $this->settings['concurrency']) * 2)->get();

                if ($images->isEmpty()) {
                    if ($this->checkExternal() || $this->checkBots() || $this->checkWordPress()) {
                        continue;
                    }

                    $this->finish();

                    return false;
                }

                $this->fetchImages($images);
                usleep(max(0, (int) $this->settings['delay_ms']) * 1000);

                continue;
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
        $this->crawl->bot_access = BotAccess::fromRobots($robotsBody, $start);
        $this->crawl->llms_txt = LlmsTxt::analyze($this->fetchText($origin.'/llms.txt'))
            + ['full' => LlmsTxt::analyze($this->fetchText($origin.'/llms-full.txt'))['status'] !== 'missing'];
        // The pages llms.txt points AI systems to are crawled too, so a broken one shows up.
        $llmsUrls = [];
        foreach ($this->crawl->llms_txt['urls'] as $url) {
            $resolved = UrlNormalizer::resolve($url, $start);
            if ($resolved && UrlNormalizer::isInternal($resolved, $start)) {
                $llmsUrls[] = $resolved;
                $this->addPage($resolved, 1);
            }
        }
        $this->crawl->llms_txt = ['urls' => $llmsUrls] + $this->crawl->llms_txt;

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
     * @param  Collection<int, CrawlPage>  $pages
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
            return $toFetch->map(fn (CrawlPage $page) => $this->withAccess($pool->as((string) $page->id), $page->url)
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
            if ($page->depth === 0) {
                $this->crawl->wp_checks = ['inventory' => WordPressProbe::inventory($body)];
                $this->crawl->save();
                $update['security_headers'] = implode(',', SecurityHeaders::problems($response->headers(), str_starts_with($page->url, 'https://'))) ?: null;
            }
            $update['size_bytes'] = strlen($body);
            $parsed = HtmlParser::parse($body, $page->url);
            $headerCanonical = self::headerCanonical((string) $response->header('Link'), $page->url);

            $update += [
                'title' => $parsed['title'],
                'meta_description' => $parsed['description'],
                'meta_robots' => $parsed['meta_robots'],
                'canonical' => $parsed['canonical'] ?? $headerCanonical,
                'canonical_conflict' => $parsed['canonical_conflict'] || ($headerCanonical !== null && $parsed['canonical'] !== null && $headerCanonical !== $parsed['canonical']),
                'h2_count' => $parsed['h2_count'],
                'question_headings' => $parsed['question_headings'],
                'long_paragraphs' => $parsed['long_paragraphs'],
                'readability' => $parsed['readability'],
                'aeo' => $parsed['aeo'],
                'is_article' => $parsed['is_article'],
                'published_at' => $parsed['published_at'],
                'modified_at' => $parsed['modified_at'],
                'has_author' => $parsed['has_author'],
                'author_url' => $parsed['author_url'] !== null ? UrlNormalizer::resolve($parsed['author_url'], $page->url) : null,
                'person_schema' => $parsed['person_schema'],
                'same_as' => $parsed['same_as'],
                'heading_skip' => $parsed['heading_skip'],
                'h1_count' => count($parsed['h1']),
                'h1' => $parsed['h1'][0] ?? null,
                'word_count' => $parsed['word_count'],
                'content_hash' => $parsed['content_hash'],
                'simhash' => $parsed['simhash'],
                'wordpress_hint' => $parsed['wordpress_hint'],
                'images_missing_alt' => $parsed['images_missing_alt'],
                'images_missing_size' => $parsed['images_missing_size'],
                'js_shell' => $parsed['js_shell'],
                'mixed_content' => $parsed['mixed_content'],
                'insecure_forms' => $parsed['insecure_forms'],
                'meta_refresh' => $parsed['meta_refresh'] ? mb_substr($parsed['meta_refresh'], 0, 250) : null,
                'page_type' => $parsed['page_type'],
                'wp_type' => $parsed['wp_type'],
                'wp_category' => $parsed['wp_category'],
                'seo_plugin' => $parsed['seo_plugin'],
                'schema_types' => $parsed['schema_types'] === [] ? null : mb_substr(implode(',', $parsed['schema_types']), 0, 250),
                'schema_errors' => $parsed['schema_errors'],
                'schema_problems' => $parsed['schema_problems'] === [] ? null : array_slice($parsed['schema_problems'], 0, 20),
                'schema_duplicates' => $parsed['schema_duplicates'] === [] ? null : implode(',', $parsed['schema_duplicates']),
                'schema_mismatch' => $parsed['schema_mismatch'] === [] ? null : $parsed['schema_mismatch'],
                'entity' => $parsed['entity'],
                'schema_vocabulary' => $parsed['schema_vocabulary'] === [] ? null : $parsed['schema_vocabulary'],
                'uncrawlable_links' => $parsed['uncrawlable_links'],
                'empty_anchors' => $parsed['empty_anchors'],
                'generic_anchors' => $parsed['generic_anchor_count'],
                'generic_anchor_texts' => $parsed['generic_anchors'] === [] ? null : mb_substr(implode(' | ', $parsed['generic_anchors']), 0, 250),
            ];

            if ($rules = CustomExtraction::valid($this->settings['custom'] ?? [])) {
                $update['custom'] = CustomExtraction::apply($rules, $body);
            }
            $update['hreflang'] = $parsed['hreflang'] === [] ? null : $parsed['hreflang'];
            // Language versions are crawled like links so their return tags can be checked.
            foreach ($parsed['hreflang'] as $alternate) {
                if (UrlNormalizer::isInternal($alternate['url'], $page->url) && ! UrlNormalizer::isAsset($alternate['url'])) {
                    $this->addPage($alternate['url'], $page->depth + 1);
                }
            }

            // rel next/prev series: crawled like links, and whether a normal link also points there is kept for the audit.
            $linked = array_column($parsed['links'], 'url');
            $pagination = [];
            foreach (array_filter($parsed['pagination']) as $key => $url) {
                $pagination[$key] = $url;
                $pagination["{$key}_linked"] = in_array($url, $linked, true);
                if (UrlNormalizer::isInternal($url, $page->url) && ! UrlNormalizer::isAsset($url)) {
                    $this->addPage($url, $page->depth + 1);
                }
            }
            $update['pagination'] = $pagination === [] ? null : $pagination;

            $update['http_internal_links'] = $this->storeLinks($page, $parsed['links']);
            $this->storeImages($page, $parsed['images']);
            $this->storeExternal($page, $parsed['links']);
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

    /**
     * Records the site's own images used on a page so they can be checked once each after the pages are crawled.
     *
     * @param  array<int, string>  $urls
     */
    private function storeImages(CrawlPage $page, array $urls): void
    {
        $urls = array_slice(array_filter($urls, fn (string $url) => UrlNormalizer::isInternal($url, $page->url)), 0, self::MAX_IMAGES_PER_PAGE);
        if ($urls === []) {
            return;
        }

        $hashes = array_map(UrlNormalizer::hash(...), $urls);
        $known = DB::table('crawl_images')->where('crawl_id', $this->crawl->id)->count();
        $room = max(0, (int) $this->settings['max_urls'] - $known);

        $existing = DB::table('crawl_images')->where('crawl_id', $this->crawl->id)->whereIn('url_hash', $hashes)->pluck('url_hash')->all();
        $rows = [];
        foreach ($urls as $i => $url) {
            if (! in_array($hashes[$i], $existing, true) && count($rows) < $room) {
                $rows[] = ['crawl_id' => $this->crawl->id, 'url' => $url, 'url_hash' => $hashes[$i], 'state' => 'queued'];
            }
        }
        DB::table('crawl_images')->insertOrIgnore($rows);

        $ids = DB::table('crawl_images')->where('crawl_id', $this->crawl->id)->whereIn('url_hash', $hashes)->pluck('id');
        DB::table('crawl_image_refs')->insert($ids->map(fn ($id) => ['crawl_id' => $this->crawl->id, 'image_id' => $id, 'page_id' => $page->id])->all());
    }

    /**
     * HEAD requests are enough: the status code shows broken images and Content-Length shows their weight.
     *
     * @param  Collection<int, CrawlImage>  $images
     */
    private function fetchImages($images): void
    {
        $responses = Http::pool(fn (Pool $pool) => $images->map(fn ($image) => $this->withAccess($pool->as((string) $image->id), $image->url)
            ->withUserAgent($this->settings['user_agent'])
            ->timeout(self::TIMEOUT_SECONDS)
            ->head($image->url))->all());

        foreach ($images as $image) {
            $response = $responses[(string) $image->id] ?? null;

            if ($response instanceof Response) {
                $length = $response->header('Content-Length');
                $image->update([
                    'state' => 'done',
                    'status_code' => $response->status(),
                    'content_type' => mb_substr(strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])), 0, 250) ?: null,
                    'size_bytes' => is_numeric($length) ? (int) $length : null,
                ]);
            } else {
                $message = $response instanceof ConnectionException ? $response->getMessage() : 'Yanıt alınamadı';
                $image->update(['state' => 'done', 'error' => mb_substr($message, 0, 250)]);
            }
        }
    }

    /**
     * Records the outgoing links to other sites on a page, each checked once after the pages are crawled.
     *
     * @param  array<int, array{url: string, nofollow: bool}>  $links
     */
    private function storeExternal(CrawlPage $page, array $links): void
    {
        if (! ($this->settings['check_external'] ?? true)) {
            return;
        }

        $urls = collect($links)->pluck('url')
            ->filter(fn (string $url) => preg_match('#^https?://#i', $url) && ! UrlNormalizer::isInternal($url, $page->url))
            ->unique()->take(self::MAX_EXTERNAL_PER_PAGE)->values()->all();
        if ($urls === []) {
            return;
        }

        $hashes = array_map(UrlNormalizer::hash(...), $urls);
        $known = DB::table('crawl_external_links')->where('crawl_id', $this->crawl->id)->count();
        $room = max(0, (int) ($this->settings['max_external'] ?? 500) - $known);

        $existing = DB::table('crawl_external_links')->where('crawl_id', $this->crawl->id)->whereIn('url_hash', $hashes)->pluck('url_hash')->all();
        $rows = [];
        foreach ($urls as $i => $url) {
            if (! in_array($hashes[$i], $existing, true) && count($rows) < $room) {
                $rows[] = ['crawl_id' => $this->crawl->id, 'url' => $url, 'url_hash' => $hashes[$i], 'state' => 'queued'];
            }
        }
        DB::table('crawl_external_links')->insertOrIgnore($rows);

        $ids = DB::table('crawl_external_links')->where('crawl_id', $this->crawl->id)->whereIn('url_hash', $hashes)->pluck('id');
        DB::table('crawl_external_refs')->insert($ids->map(fn ($id) => ['crawl_id' => $this->crawl->id, 'link_id' => $id, 'page_id' => $page->id])->all());
    }

    /**
     * Checks one round of queued external links; false once none are left. HEAD first, GET when a server refuses HEAD.
     */
    private function checkExternal(): bool
    {
        $links = DB::table('crawl_external_links')->where('crawl_id', $this->crawl->id)->where('state', 'queued')
            ->orderBy('id')->limit(max(1, (int) $this->settings['concurrency']) * 3)->get();
        if ($links->isEmpty()) {
            return false;
        }

        $request = fn (Pool $pool, $link, string $method) => $pool->as((string) $link->id)
            ->withUserAgent($this->settings['user_agent'])
            ->timeout(self::BOT_TIMEOUT_SECONDS)
            ->{$method}($link->url);

        $responses = Http::pool(fn (Pool $pool) => $links->map(fn ($link) => $request($pool, $link, 'head'))->all());
        $retry = $links->filter(fn ($link) => ($responses[(string) $link->id] ?? null) instanceof Response
            && in_array($responses[(string) $link->id]->status(), [400, 403, 404, 405, 501], true));
        if ($retry->isNotEmpty()) {
            foreach (Http::pool(fn (Pool $pool) => $retry->map(fn ($link) => $request($pool, $link, 'get'))->all()) as $id => $response) {
                $responses[$id] = $response;
            }
        }

        foreach ($links as $link) {
            $response = $responses[(string) $link->id] ?? null;
            $update = $response instanceof Response
                ? ['state' => 'done', 'status_code' => $response->status()]
                : ['state' => 'done', 'error' => mb_substr($response instanceof ConnectionException ? $response->getMessage() : 'Yanıt alınamadı', 0, 250)];
            DB::table('crawl_external_links')->where('id', $link->id)->update($update);
        }

        return true;
    }

    private function addPage(string $url, int $depth, bool $inSitemap = false): void
    {
        // The start URL is always crawled; the project's include/exclude rules apply to everything found from it.
        if ($depth > 0 && ! $this->filter()->allows($url)) {
            return;
        }

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

    /**
     * Runs one round of bot access requests against the start page; false once none are left.
     */
    private function checkBots(): bool
    {
        $rows = $this->crawl->bot_access ?? [];
        $tokens = array_slice(BotAccess::pending($rows), 0, max(1, (int) $this->settings['concurrency']));
        if ($tokens === []) {
            return false;
        }

        $start = $this->crawl->pages()->where('depth', 0)->orderBy('id')->value('url') ?? $this->crawl->project->base_url;
        $this->crawl->bot_access = BotAccess::fetch($rows, $tokens, $start, self::BOT_TIMEOUT_SECONDS);
        $this->crawl->save();
        usleep(max(0, (int) $this->settings['delay_ms']) * 1000);

        return true;
    }

    /**
     * Runs one round of the external WordPress checks; false once none are left or the site is not WordPress.
     */
    private function checkWordPress(): bool
    {
        $checks = $this->crawl->wp_checks ?? [];
        if (($checks['skipped'] ?? false) === true) {
            return false;
        }

        if (! isset($checks['probes'])) {
            $isWordPress = $this->crawl->project->platform === 'wordpress' || $this->crawl->pages()->where('wordpress_hint', true)->exists();
            if (! $isWordPress) {
                $this->crawl->wp_checks = ['skipped' => true];
                $this->crawl->save();

                return false;
            }
            $checks['inventory'] ??= ['plugins' => [], 'themes' => []];
            $checks['probes'] = WordPressProbe::initial($checks['inventory']);
        }

        $pending = array_keys(array_filter($checks['probes'], fn ($probe) => $probe['state'] === 'pending'));
        if ($pending === []) {
            return false;
        }

        $start = $this->crawl->pages()->where('depth', 0)->orderBy('id')->value('url') ?? $this->crawl->project->base_url;
        $origin = parse_url($start, PHP_URL_SCHEME).'://'.parse_url($start, PHP_URL_HOST);
        $keys = array_slice($pending, 0, max(1, (int) $this->settings['concurrency']) * 2);

        $checks['probes'] = WordPressProbe::run($checks['probes'], $keys, $origin, $this->settings['user_agent'], self::BOT_TIMEOUT_SECONDS);
        $this->crawl->wp_checks = $checks;
        $this->crawl->save();
        usleep(max(0, (int) $this->settings['delay_ms']) * 1000);

        return true;
    }

    /**
     * The canonical URL from an HTTP Link header (Link: <https://example.com/a>; rel="canonical"), which Google
     * reads like the HTML tag.
     */
    public static function headerCanonical(string $header, string $pageUrl): ?string
    {
        foreach (explode(',', $header) as $part) {
            if (preg_match('/<([^>]+)>\s*;.*rel\s*=\s*"?([^";]*)/i', $part, $match) && in_array('canonical', preg_split('/\s+/', strtolower(trim($match[2]))), true)) {
                return UrlNormalizer::resolve(trim($match[1]), $pageUrl);
            }
        }

        return null;
    }

    private function filter(): UrlFilter
    {
        return $this->filter ??= new UrlFilter($this->settings['include'] ?? '', $this->settings['exclude'] ?? '');
    }

    private function finish(): void
    {
        DB::statement(
            'UPDATE crawl_pages SET inlinks = (SELECT COUNT(*) FROM crawl_links WHERE crawl_links.crawl_id = crawl_pages.crawl_id AND crawl_links.to_hash = crawl_pages.url_hash) WHERE crawl_id = ?',
            [$this->crawl->id],
        );
        LinkScore::compute($this->crawl);

        $this->crawl->detected_platform = $this->crawl->pages()->where('wordpress_hint', true)->exists() ? 'wordpress' : null;
        $this->crawl->save();

        (new Auditor)->run($this->crawl);

        $this->crawl->update(['status' => 'completed', 'finished_at' => now()]);
        CrawlAlerts::newIssues($this->crawl);

        PageSpeed::prepare($this->crawl);
        TaskBuilder::sync($this->crawl->project);
    }

    private function fetchText(string $url): ?string
    {
        try {
            $response = $this->withAccess(Http::withUserAgent($this->settings['user_agent']), $url)->timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Adds the project's basic auth and extra headers, only for requests to the project's own host so the
     * password never reaches another site (external links, CDNs).
     */
    private function withAccess(PendingRequest $request, string $url): PendingRequest
    {
        $this->access ??= $this->crawl->project->crawlAccess();
        $own = preg_replace('/^www\./', '', (string) parse_url($this->crawl->project->base_url, PHP_URL_HOST));
        if (preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) !== $own) {
            return $request;
        }

        if ($this->access['user'] !== null) {
            $request->withBasicAuth($this->access['user'], (string) $this->access['password']);
        }

        return $this->access['headers'] === [] ? $request : $request->withHeaders($this->access['headers']);
    }
}
