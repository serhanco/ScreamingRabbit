<?php

namespace App\Audit;

use App\Models\Crawl;
use App\Models\CrawlPage;
use Illuminate\Support\Facades\DB;

/**
 * Runs the rule catalog over a finished crawl and stores one issue row per affected URL.
 */
class Auditor
{
    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    private array $catalog;

    private Crawl $crawl;

    /** @var array<int, string> page id => sha1 of the canonical URL it points at */
    private array $canonicalTargets = [];

    /** @var array<string, int> SEO plugin key => a page id that shows it */
    private array $seoPlugins = [];

    public function run(Crawl $crawl): void
    {
        $this->crawl = $crawl;
        $this->catalog = RuleCatalog::all();
        $this->buffer = [];

        $crawl->issues()->delete();

        $platform = $crawl->project->platform === 'wordpress' || $crawl->detected_platform === 'wordpress';

        /** @var array<string, array<string, array<int, int>>> $groups */
        $groups = ['title' => [], 'meta_description' => [], 'h1' => [], 'content' => []];
        /** @var array<string, array{id: int, url: string, redirect_to: ?string}> $redirects */
        $redirects = [];
        $this->canonicalTargets = [];
        $this->seoPlugins = [];

        $crawl->pages()->orderBy('id')->chunkById(500, function ($pages) use (&$groups, &$redirects, $platform) {
            foreach ($pages as $page) {
                $this->auditPage($page, $platform, $groups);

                if ($page->state === 'done' && $page->status_code >= 300 && $page->status_code < 400 && $page->redirect_to) {
                    $redirects[$page->url_hash] = ['id' => $page->id, 'url' => $page->url, 'redirect_to' => $page->redirect_to];
                }
            }
        });

        $this->auditRedirects($redirects);
        $this->auditDuplicates($groups);
        $this->auditCanonicalTargets();
        $this->auditSeoPlugins($platform);
        $this->auditImages();
        $this->flush();

        $crawl->update(['health_score' => $this->healthScore()]);
    }

    /**
     * 0-100. Every rule that fires costs part of its severity weight (30% for existing at all, the rest in
     * proportion to the share of pages it affects), so one broken page matters, but a site-wide problem matters more.
     */
    private function healthScore(): int
    {
        $total = max(1, $this->crawl->pages()->where('state', 'done')->count());
        $weights = ['high' => 12, 'medium' => 6, 'low' => 2, 'info' => 0];

        $penalty = 0.0;
        $perRule = $this->crawl->issues()->selectRaw('rule, severity, COUNT(DISTINCT page_id) as pages')->groupBy('rule', 'severity')->get();

        foreach ($perRule as $row) {
            $ratio = min(1, $row->pages / $total);
            $penalty += $weights[$row->severity] * (0.3 + 0.7 * $ratio);
        }

        return (int) max(0, min(100, round(100 - $penalty)));
    }

    /**
     * @param  array<string, array<string, array<int, int>>>  $groups
     */
    private function auditPage(CrawlPage $page, bool $wordpress, array &$groups): void
    {
        if ($page->state === 'blocked') {
            $this->emit('blocked_by_robots', $page);

            return;
        }

        $status = $page->status_code;

        if ($status === null) {
            $timedOut = stripos((string) $page->error, 'timed out') !== false || stripos((string) $page->error, 'error 28') !== false;
            $this->emit($timedOut ? 'timeout' : 'fetch_failed', $page, $page->error);

            return;
        }

        $inlinks = $page->inlinks > 0 ? $page->inlinks.' iç linkten erişiliyor' : null;

        if ($status >= 500) {
            $this->emit('http_5xx', $page, "HTTP {$status}".($inlinks ? ', '.$inlinks : ''));
        } elseif ($status >= 400) {
            $this->emit('http_4xx', $page, "HTTP {$status}".($inlinks ? ', '.$inlinks : ''));
        } elseif ($status >= 300) {
            if (in_array($status, [302, 303, 307], true)) {
                $this->emit('redirect_temporary', $page, "HTTP {$status} → {$page->redirect_to}");
            }
            if ($page->inlinks > 0) {
                $this->emit('link_to_redirect', $page, $inlinks);
            }
        }

        if ($page->in_sitemap && ! $page->isIndexable()) {
            $this->emit('sitemap_non_indexable', $page);
        }

        if (! $page->isHtmlOk()) {
            return;
        }

        if (str_starts_with($page->url, 'http://')) {
            $this->emit('http_page', $page);
        }

        if ($page->mixed_content > 0) {
            $this->emit('mixed_content', $page, $page->mixed_content.' HTTP kaynak');
        }

        if ($page->http_internal_links > 0) {
            $this->emit('https_to_http_link', $page, $page->http_internal_links.' HTTP iç link');
        }

        if ($page->meta_refresh) {
            $this->emit('meta_refresh', $page, $page->meta_refresh);
        }

        foreach (array_filter(explode(',', (string) $page->seo_plugin)) as $plugin) {
            $this->seoPlugins[$plugin] ??= $page->id;
        }

        $noindex = str_contains(strtolower((string) $page->meta_robots), 'noindex')
            || str_contains(strtolower((string) $page->x_robots), 'noindex');

        if ($noindex) {
            $this->emit('noindex', $page);
        } elseif ($page->canonical === null || $page->canonical === '') {
            $this->emit('canonical_missing', $page);
        } elseif ($page->canonical !== $page->url) {
            $this->emit('canonical_other', $page, $page->canonical);
            $this->canonicalTargets[$page->id] = sha1($page->canonical);
        }

        $this->auditUrl($page);

        if (! $page->isIndexable()) {
            return;
        }

        if ($this->crawl->sitemap_found && ! $page->in_sitemap) {
            $this->emit('sitemap_missing_indexable', $page);
        }

        $title = (string) $page->title;
        if ($title === '') {
            $this->emit('title_missing', $page);
        } else {
            $length = mb_strlen($title);
            if ($length > RuleCatalog::threshold('title_max')) {
                $this->emit('title_too_long', $page, "{$length} karakter");
            } elseif ($length < RuleCatalog::threshold('title_min')) {
                $this->emit('title_too_short', $page, "{$length} karakter");
            }
            $groups['title'][md5(mb_strtolower($title))][] = $page->id;
        }

        $description = (string) $page->meta_description;
        if ($description === '') {
            $this->emit('meta_description_missing', $page);
        } else {
            $length = mb_strlen($description);
            if ($length > RuleCatalog::threshold('description_max')) {
                $this->emit('meta_description_too_long', $page, "{$length} karakter");
            } elseif ($length < RuleCatalog::threshold('description_min')) {
                $this->emit('meta_description_too_short', $page, "{$length} karakter");
            }
            $groups['meta_description'][md5(mb_strtolower($description))][] = $page->id;
        }

        if ($page->h1_count === 0) {
            $this->emit('h1_missing', $page);
        } else {
            if ($page->h1_count > 1) {
                $this->emit('h1_multiple', $page, $page->h1_count.' adet H1');
            }
            if ((string) $page->h1 !== '') {
                $groups['h1'][md5(mb_strtolower((string) $page->h1))][] = $page->id;
            }
        }

        if ($page->images_missing_alt > 0) {
            $this->emit('images_missing_alt', $page, $page->images_missing_alt.' görselde alt yok');
        }

        if ($page->inlinks === 1 && $page->depth > 0) {
            $this->emit('single_inlink', $page);
        }

        if ($wordpress && $page->page_type === 'attachment') {
            $this->emit('wp_attachment_page', $page);
        }

        if ($wordpress && $page->page_type === 'archive' && $page->word_count !== null && $page->word_count < RuleCatalog::threshold('low_word_count')) {
            $this->emit('wp_thin_archive', $page, "{$page->word_count} kelime");
        }

        if ($page->word_count !== null && $page->word_count < RuleCatalog::threshold('low_word_count')) {
            $this->emit('low_content', $page, "{$page->word_count} kelime");
        }

        if ($page->word_count > 0 && $page->content_hash) {
            $groups['content'][$page->content_hash][] = $page->id;
        }

        if ($wordpress && preg_match('/(^|&)(p|page_id|attachment_id)=\d+/', (string) parse_url($page->url, PHP_URL_QUERY))
            && ($page->canonical === null || $page->canonical === '' || $page->canonical === $page->url)) {
            $this->emit('wp_raw_url', $page);
        }
    }

    private function auditUrl(CrawlPage $page): void
    {
        $parts = parse_url($page->url);
        $path = $parts['path'] ?? '/';

        if (preg_match('/[A-Z]/', rawurldecode($path))) {
            $this->emit('url_uppercase', $page);
        }
        if (str_contains($path, '_')) {
            $this->emit('url_underscore', $page);
        }
        if (strlen($page->url) > RuleCatalog::threshold('url_max_length')) {
            $this->emit('url_too_long', $page, strlen($page->url).' karakter');
        }
        if (isset($parts['query'])) {
            $this->emit('url_parameters', $page);
        }
        if (preg_match('/%[0-9A-Fa-f]{2}|[^\x20-\x7e]/', $path)) {
            $this->emit('url_non_ascii', $page);
        }
        if ($page->depth > RuleCatalog::threshold('max_depth')) {
            $this->emit('crawl_depth_high', $page, "{$page->depth} tık derinlikte");
        }
    }

    /**
     * @param  array<string, array{id: int, url: string, redirect_to: ?string}>  $redirects
     */
    private function auditRedirects(array $redirects): void
    {
        foreach ($redirects as $redirect) {
            $target = $redirects[sha1((string) $redirect['redirect_to'])] ?? null;

            if ($target === null) {
                continue;
            }

            if ($target['redirect_to'] === $redirect['url']) {
                $this->emitFor('redirect_loop', $redirect['id']);
            } else {
                $this->emitFor('redirect_chain', $redirect['id'], '→ '.$redirect['redirect_to'].' → '.$target['redirect_to']);
            }
        }
    }

    /**
     * @param  array<string, array<string, array<int, int>>>  $groups
     */
    private function auditDuplicates(array $groups): void
    {
        $map = [
            'title' => 'title_duplicate',
            'meta_description' => 'meta_description_duplicate',
            'h1' => 'h1_duplicate',
            'content' => 'duplicate_content',
        ];

        foreach ($map as $field => $rule) {
            foreach ($groups[$field] as $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                foreach ($ids as $id) {
                    $this->emitFor($rule, $id, count($ids).' sayfada aynı');
                }
            }
        }
    }

    private function auditCanonicalTargets(): void
    {
        if ($this->canonicalTargets === []) {
            return;
        }

        $targets = [];
        foreach (array_chunk(array_unique($this->canonicalTargets), 500) as $hashes) {
            foreach ($this->crawl->pages()->whereIn('url_hash', $hashes)->get(['url_hash', 'url', 'state', 'status_code', 'meta_robots', 'x_robots']) as $target) {
                $targets[$target->url_hash] = $target;
            }
        }

        foreach ($this->canonicalTargets as $pageId => $hash) {
            $target = $targets[$hash] ?? null;
            if ($target === null || $target->state !== 'done') {
                continue;
            }

            $noindex = str_contains(strtolower((string) $target->meta_robots.' '.(string) $target->x_robots), 'noindex');
            if ($target->status_code !== 200 || $noindex) {
                $this->emitFor('canonical_target_bad', $pageId, $target->url.($target->status_code !== 200 ? ' → HTTP '.($target->status_code ?? 'yanıt yok') : ' → noindex'));
            }
        }
    }

    private function auditSeoPlugins(bool $wordpress): void
    {
        if ($wordpress && count($this->seoPlugins) > 1) {
            $start = $this->crawl->pages()->orderBy('depth')->orderBy('id')->value('id');
            $this->emitFor('wp_multiple_seo_plugins', $start, implode(', ', array_keys($this->seoPlugins)));
        }
    }

    private function auditImages(): void
    {
        $maxBytes = RuleCatalog::threshold('image_max_kb') * 1024;

        DB::table('crawl_image_refs')
            ->join('crawl_images', 'crawl_images.id', '=', 'crawl_image_refs.image_id')
            ->where('crawl_image_refs.crawl_id', $this->crawl->id)
            ->where('crawl_images.state', 'done')
            ->where(fn ($q) => $q->where('crawl_images.status_code', '>=', 400)
                ->orWhereNull('crawl_images.status_code')
                ->orWhere('crawl_images.size_bytes', '>', $maxBytes))
            ->select('crawl_image_refs.id', 'crawl_image_refs.page_id', 'crawl_images.url', 'crawl_images.status_code', 'crawl_images.size_bytes')
            ->orderBy('crawl_image_refs.id')
            ->chunk(500, function ($rows) use ($maxBytes) {
                foreach ($rows as $row) {
                    if ($row->status_code === null || $row->status_code >= 400) {
                        $this->emitFor('image_broken', $row->page_id, ($row->status_code ? 'HTTP '.$row->status_code : 'Yanıt yok').': '.$row->url);
                    } elseif ($row->size_bytes > $maxBytes) {
                        $this->emitFor('image_too_large', $row->page_id, round($row->size_bytes / 1024).' KB: '.$row->url);
                    }
                }
            });
    }

    private function emit(string $rule, CrawlPage $page, ?string $detail = null): void
    {
        $this->emitFor($rule, $page->id, $detail);
    }

    private function emitFor(string $rule, int $pageId, ?string $detail = null): void
    {
        $this->buffer[] = [
            'crawl_id' => $this->crawl->id,
            'page_id' => $pageId,
            'rule' => $rule,
            'severity' => $this->catalog[$rule]['severity'],
            'detail' => $detail === null ? null : mb_substr($detail, 0, 250),
        ];

        if (count($this->buffer) >= 500) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->buffer !== []) {
            DB::table('crawl_issues')->insert($this->buffer);
            $this->buffer = [];
        }
    }
}
