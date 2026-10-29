<?php

namespace App\Audit;

use App\Crawler\HtmlParser;
use App\Crawler\UrlNormalizer;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\ProjectScore;
use App\Performance\PageSpeed;
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

    /** @var array<int, array{url: string, entries: array<int, array{lang: string, url: string}>}> page id => its hreflang tags */
    private array $hreflang = [];

    /** @var array<int, array{url: string, next?: string, prev?: string, next_linked?: bool, prev_linked?: bool}> page id => its rel next/prev links */
    private array $pagination = [];

    /** @var array<string, int> url hash of an author page => articles linking to it */
    private array $authorPages = [];

    /** @var array<string, array<string, int>> 'name'|'phone' => value as written => pages carrying it */
    private array $nap = [];

    /** @var array<int, array{0: string, 1: string}> page id => [url hash, last update date] of articles older than a year */
    private array $staleArticles = [];

    private const STALE_DAYS = 365;

    /** @var array<int, array<int, int>> AI readiness score => page ids */
    private array $aiScores = [];

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
        $groups = ['title' => [], 'meta_description' => [], 'h1' => [], 'content' => [], 'simhash' => []];
        /** @var array<string, array{id: int, url: string, redirect_to: ?string}> $redirects */
        $redirects = [];
        $this->canonicalTargets = [];
        $this->seoPlugins = [];
        $this->hreflang = [];
        $this->pagination = [];
        $this->authorPages = [];
        $this->nap = ['name' => [], 'phone' => []];
        $this->staleArticles = [];
        $this->aiScores = [];

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
        $this->auditNearDuplicates($groups['simhash']);
        $this->auditCanonicalTargets();
        $this->auditHreflang();
        $this->auditPagination();
        $this->auditAuthorPages();
        $this->auditSeoPlugins($platform);
        $this->auditImages();
        $this->auditExternalLinks();
        $this->auditBotAccess();
        $this->auditAiReadiness();
        $this->auditPageSpeed();
        if ($platform) {
            $this->auditWordPress();
        }
        $this->flush();
        $this->storeAiScores();

        $crawl->update(['health_score' => HealthScore::compute($crawl)]);
        ProjectScore::record($crawl);
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
            $this->emit(self::isPaginated($page) ? 'pagination_canonical' : 'canonical_other', $page, $page->canonical);
            $this->canonicalTargets[$page->id] = sha1($page->canonical);
        }
        if ($page->canonical_conflict) {
            $this->emit('canonical_conflict', $page);
        }

        if ($page->status_code === 200 && ! empty($page->entity)) {
            foreach (['name', 'phone'] as $key) {
                if (filled($page->entity[$key] ?? null)) {
                    $this->nap[$key][$page->entity[$key]] = ($this->nap[$key][$page->entity[$key]] ?? 0) + 1;
                }
            }
        }

        if ($page->status_code === 200 && ! empty($page->pagination)) {
            $this->pagination[$page->id] = ['url' => $page->url] + $page->pagination;
        }

        if ($page->status_code === 200 && ! empty($page->hreflang)) {
            $this->hreflang[$page->id] = ['url' => $page->url, 'entries' => $page->hreflang];
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

        if ($page->isHtmlOk()) {
            $this->aiScores[AiReadiness::score($page)][] = $page->id;
        }

        if ($page->is_article || $page->wp_type === 'post') {
            if ($page->published_at === null && $page->modified_at === null) {
                $this->emit('article_date_missing', $page);
            }
            if (! $page->has_author) {
                $this->emit('article_author_missing', $page);
            } elseif ($page->author_url === null) {
                $this->emit('author_page_missing', $page);
            } elseif (UrlNormalizer::isInternal($page->author_url, $page->url)) {
                $hash = sha1($page->author_url);
                $this->authorPages[$hash] = ($this->authorPages[$hash] ?? 0) + 1;
            }
            $updated = $page->modified_at ?? $page->published_at;
            if ($updated !== null && $updated->lt(now()->subDays(self::STALE_DAYS))) {
                $this->staleArticles[$page->id] = [$page->url_hash, $updated->format('d.m.Y')];
            }
        }
        if ($page->long_paragraphs >= 2) {
            $this->emit('long_paragraphs', $page, $page->long_paragraphs.' paragraf '.HtmlParser::LONG_PARAGRAPH_WORDS.' kelimeden uzun');
        }

        if (! empty($page->aeo)) {
            $this->auditAnswerSignals($page);
        }

        if ($page->readability !== null && $page->readability < Readability::HARD) {
            $this->emit('readability_hard', $page, $page->readability.' / 100 ('.Readability::label($page->readability).')');
        }

        if ($page->heading_skip !== null) {
            $this->emit('headings_non_sequential', $page, $page->heading_skip);
        }
        if ($page->h2_count !== null && (int) $page->h2_count === 0 && $page->word_count >= RuleCatalog::threshold('low_word_count')) {
            $this->emit('h2_missing', $page);
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

        if ($page->schema_errors > 0) {
            $this->emit('schema_invalid_json', $page, $page->schema_errors.' JSON-LD bloğu okunamadı');
        }
        $required = collect($page->schema_problems ?? [])->filter(fn (array $p) => $p['required'] !== []);
        if ($required->isNotEmpty()) {
            $this->emit('schema_missing_required', $page, mb_substr($required->map(fn (array $p) => $p['type'].': '.implode(', ', str_replace('|', ' veya ', $p['required'])))->implode(' · '), 0, 250));
        }
        $recommended = collect($page->schema_problems ?? [])->filter(fn (array $p) => $p['recommended'] !== []);
        if ($recommended->isNotEmpty()) {
            $this->emit('schema_missing_recommended', $page, mb_substr($recommended->map(fn (array $p) => $p['type'].': '.implode(', ', str_replace('|', ' veya ', $p['recommended'])))->implode(' · '), 0, 250));
        }
        if (! empty($page->schema_vocabulary)) {
            $this->emit('schema_vocabulary_error', $page, mb_substr(implode(' · ', $page->schema_vocabulary), 0, 250));
        }
        if (! empty($page->schema_mismatch)) {
            $this->emit('schema_page_mismatch', $page, implode(' · ', $page->schema_mismatch));
        }
        if (filled($page->schema_duplicates)) {
            $this->emit('schema_duplicate_type', $page, str_replace(',', ', ', $page->schema_duplicates).' birden fazla kez');
        }

        if ($page->uncrawlable_links > 0) {
            $this->emit('uncrawlable_links', $page, $page->uncrawlable_links.' link');
        }
        if ($page->empty_anchors > 0) {
            $this->emit('anchor_text_empty', $page, $page->empty_anchors.' link');
        }
        if ($page->generic_anchors > 0) {
            $this->emit('anchor_text_generic', $page, mb_substr($page->generic_anchors.' link: '.$page->generic_anchor_texts, 0, 250));
        }
        if ($page->depth === 0 && filled($page->security_headers)) {
            $this->emit('security_headers_missing', $page, str_replace(',', ', ', $page->security_headers));
        }

        if ($page->images_missing_alt > 0) {
            $this->emit('images_missing_alt', $page, $page->images_missing_alt.' görselde alt yok');
        }

        if ($page->images_missing_size > 0) {
            $this->emit('images_missing_size', $page, $page->images_missing_size.' görselde width/height yok');
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

        if ($page->js_shell) {
            $this->emit('content_requires_js', $page, "Ham HTML'de {$page->word_count} kelime");
        }

        if ($page->word_count !== null && $page->word_count < RuleCatalog::threshold('low_word_count')) {
            $this->emit('low_content', $page, "{$page->word_count} kelime");
        }

        if ($page->word_count > 0 && $page->content_hash) {
            $groups['content'][$page->content_hash][] = $page->id;
        }
        if ($page->simhash) {
            $groups['simhash'][$page->id] = $page->simhash.$page->content_hash;
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

    /**
     * @param  array<int, string>  $pages  page id => simhash followed by the exact content hash
     */
    private function auditNearDuplicates(array $pages): void
    {
        $fingerprints = array_map(fn (string $value) => substr($value, 0, 16), $pages);
        $matches = SimHash::nearDuplicates($fingerprints);
        if ($matches === []) {
            return;
        }

        // Exact copies are already reported as duplicate content.
        foreach ($matches as $id => $others) {
            $matches[$id] = array_values(array_filter($others, fn (int $other) => substr($pages[$other], 16) !== substr($pages[$id], 16)));
        }
        $matches = array_filter($matches);

        $urls = [];
        foreach (array_chunk(array_unique(array_merge(...array_values($matches))), 500) as $ids) {
            $urls += $this->crawl->pages()->whereIn('id', $ids)->pluck('url', 'id')->all();
        }
        foreach ($matches as $id => $others) {
            $detail = $urls[$others[0]].(count($others) > 1 ? ' ve '.(count($others) - 1).' sayfa daha' : '');
            $this->emitFor('near_duplicate_content', $id, $detail);
        }
    }

    private function auditCanonicalTargets(): void
    {
        if ($this->canonicalTargets === []) {
            return;
        }

        $targets = [];
        foreach (array_chunk(array_unique($this->canonicalTargets), 500) as $hashes) {
            foreach ($this->crawl->pages()->whereIn('url_hash', $hashes)->get(['url_hash', 'url', 'state', 'status_code', 'meta_robots', 'x_robots', 'canonical']) as $target) {
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
            } elseif (filled($target->canonical) && $target->canonical !== $target->url) {
                $this->emitFor('canonical_chain', $pageId, $target->url.' → '.$target->canonical);
            }
        }
    }

    /**
     * Each language version must list itself and every other version, and each listed version must answer
     * 200, be indexable and point back (Google: "Tell Google about localized versions of your page").
     */
    private function auditHreflang(): void
    {
        if ($this->hreflang === []) {
            return;
        }

        $targetHashes = [];
        foreach ($this->hreflang as $page) {
            foreach ($page['entries'] as $entry) {
                $targetHashes[sha1($entry['url'])] = true;
            }
        }
        $targets = [];
        foreach (array_chunk(array_keys($targetHashes), 500) as $hashes) {
            foreach ($this->crawl->pages()->whereIn('url_hash', $hashes)->get(['id', 'url_hash', 'url', 'state', 'status_code', 'meta_robots', 'x_robots', 'canonical']) as $target) {
                $targets[$target->url_hash] = $target;
            }
        }

        foreach ($this->hreflang as $pageId => $page) {
            $langs = array_map(fn (array $entry) => strtolower($entry['lang']), $page['entries']);
            $urls = array_column($page['entries'], 'url');

            $invalid = array_unique(array_filter(array_column($page['entries'], 'lang'), fn (string $lang) => ! Hreflang::isValid($lang)));
            if ($invalid !== []) {
                $this->emitFor('hreflang_invalid_code', $pageId, implode(', ', $invalid));
            }
            if (! in_array($page['url'], $urls, true)) {
                $this->emitFor('hreflang_missing_self', $pageId);
            }
            if (! in_array('x-default', $langs, true)) {
                $this->emitFor('hreflang_missing_x_default', $pageId);
            }

            $bad = [];
            $noReturn = [];
            foreach (array_unique($urls) as $url) {
                $target = $targets[sha1($url)] ?? null;
                if ($url === $page['url'] || $target === null || $target->state !== 'done') {
                    continue;
                }
                $noindex = str_contains(strtolower((string) $target->meta_robots.' '.(string) $target->x_robots), 'noindex');
                if ($target->status_code !== 200) {
                    $bad[] = $url.' → HTTP '.($target->status_code ?? 'yanıt yok');
                } elseif ($noindex) {
                    $bad[] = $url.' → noindex';
                } elseif (filled($target->canonical) && $target->canonical !== $url) {
                    $bad[] = $url.' → canonical başka adres';
                } elseif (! in_array($page['url'], array_column($this->hreflang[$target->id]['entries'] ?? [], 'url'), true)) {
                    $noReturn[] = $url;
                }
            }
            if ($bad !== []) {
                $this->emitFor('hreflang_target_bad', $pageId, implode(', ', $bad));
            }
            if ($noReturn !== []) {
                $this->emitFor('hreflang_missing_return', $pageId, implode(', ', $noReturn));
            }
        }
    }

    /**
     * Page 2, 3… of a series: has a rel="prev" link or a page number in the URL (/page/2/, ?page=2, ?paged=2).
     */
    private static function isPaginated(CrawlPage $page): bool
    {
        if (! empty($page->pagination['prev'])) {
            return true;
        }

        return (bool) preg_match('#/page/([2-9]|\d{2,})/?(?:$|\?)|[?&](?:page|paged)=([2-9]|\d{2,})(?:&|$)#', $page->url);
    }

    /**
     * Google no longer reads rel next/prev, so every page of a series must be reachable through a normal link,
     * and the declared next/previous pages should answer 200.
     */
    /**
     * Yoast and Rank Math print a schema graph on every page (WebSite + the organisation or person behind the site);
     * a missing WebSite node or an organisation without a logo means the plugin's schema settings are incomplete
     * or another plugin strips its output.
     *
     * @param  array<int, string>  $types  schema types on the home page
     */
    private function auditSeoPluginGraph(CrawlPage $home, array $types): void
    {
        $plugins = array_intersect(explode(',', (string) $home->seo_plugin), ['yoast', 'rankmath']);
        if ($plugins === [] || ! ($this->crawl->project->platform === 'wordpress' || $this->crawl->detected_platform === 'wordpress')) {
            return;
        }

        $missing = [];
        if (! in_array('WebSite', $types, true)) {
            $missing[] = 'WebSite düğümü yok';
        }
        if (! empty($home->entity) && filled($home->entity['name'] ?? null) && ! ($home->entity['logo'] ?? true)) {
            $missing[] = 'kurum logosu yok';
        }
        if ($missing !== []) {
            $label = ['yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math'][reset($plugins)];
            $this->emit('wp_seo_schema_incomplete', $home, $label.': '.implode(', ', $missing));
        }
    }

    /**
     * The organisation's name and phone in structured data should read the same on every page (consistent NAP);
     * differing values blur the entity for Google and AI assistants. Names are compared ignoring case and spacing.
     */
    private function auditNap(CrawlPage $home): void
    {
        $variants = [];
        $names = collect($this->nap['name'])->groupBy(fn ($count, $name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($name))), true);
        if ($names->count() > 1) {
            $variants[] = 'Ad: '.$names->map(fn ($group) => '"'.$group->keys()->first().'" ('.$group->sum().' sayfa)')->implode(', ');
        }
        if (count($this->nap['phone']) > 1) {
            $variants[] = 'Telefon: '.collect($this->nap['phone'])->map(fn ($count, $phone) => "…{$phone} ({$count} sayfa)")->implode(', ');
        }
        if ($variants !== []) {
            $this->emit('nap_inconsistent', $home, mb_substr(implode(' · ', $variants), 0, 250));
        }
    }

    /**
     * Content shaped so answer engines can lift it: a question heading answered right below, lists or tables in long
     * texts, and articles that cite outside sources.
     */
    private function auditAnswerSignals(CrawlPage $page): void
    {
        $aeo = $page->aeo;
        if (($aeo['questions'] ?? 0) > 0 && ($aeo['answered'] ?? 0) === 0) {
            $this->emit('question_answer_missing', $page, $aeo['questions'].' soru başlığı');
        }
        if ((int) $page->word_count >= self::LIST_WORDS && ($aeo['lists'] ?? 0) + ($aeo['tables'] ?? 0) === 0) {
            $this->emit('no_lists_tables', $page, $page->word_count.' kelime');
        }
        if (($page->is_article || $page->wp_type === 'post') && (int) $page->word_count >= self::SOURCE_WORDS && ($aeo['sources'] ?? 0) === 0) {
            $this->emit('external_sources_missing', $page);
        }
    }

    /**
     * Author pages should describe the person with Person (or ProfilePage) schema so Google can tie articles to a
     * real, credible author (E-E-A-T).
     */
    private function auditAuthorPages(): void
    {
        foreach (array_chunk(array_keys($this->authorPages), 500) as $hashes) {
            $pages = $this->crawl->pages()->whereIn('url_hash', $hashes)->where('state', 'done')->where('status_code', 200)->where('person_schema', false)->get();
            foreach ($pages as $page) {
                $this->emit('author_schema_missing', $page, $this->authorPages[$page->url_hash].' yazı bu yazar sayfasına bağlı');
            }
        }
    }

    private function auditPagination(): void
    {
        if ($this->pagination === []) {
            return;
        }

        $hashes = [];
        foreach ($this->pagination as $page) {
            foreach (['next', 'prev'] as $key) {
                if (isset($page[$key])) {
                    $hashes[sha1($page[$key])] = true;
                }
            }
        }
        $targets = [];
        foreach (array_chunk(array_keys($hashes), 500) as $chunk) {
            foreach ($this->crawl->pages()->whereIn('url_hash', $chunk)->get(['url_hash', 'state', 'status_code']) as $target) {
                $targets[$target->url_hash] = $target;
            }
        }

        foreach ($this->pagination as $pageId => $page) {
            $bad = [];
            $unlinked = [];
            foreach (['next' => 'sonraki', 'prev' => 'önceki'] as $key => $label) {
                if (! isset($page[$key])) {
                    continue;
                }
                $target = $targets[sha1($page[$key])] ?? null;
                if ($target !== null && $target->state === 'done' && $target->status_code !== 200) {
                    $bad[] = "{$label}: {$page[$key]} → HTTP ".($target->status_code ?? 'yanıt yok');
                }
                if (! ($page["{$key}_linked"] ?? true)) {
                    $unlinked[] = "{$label}: {$page[$key]}";
                }
            }
            if ($bad !== []) {
                $this->emitFor('pagination_target_bad', $pageId, implode(', ', $bad));
            }
            if ($unlinked !== []) {
                $this->emitFor('pagination_not_linked', $pageId, implode(', ', $unlinked));
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

    private function auditExternalLinks(): void
    {
        DB::table('crawl_external_refs')
            ->join('crawl_external_links', 'crawl_external_links.id', '=', 'crawl_external_refs.link_id')
            ->where('crawl_external_refs.crawl_id', $this->crawl->id)
            ->where('crawl_external_links.state', 'done')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('crawl_external_links.status_code', '>=', 400)->whereNotIn('crawl_external_links.status_code', self::EXTERNAL_BLOCKED))
                ->orWhereNull('crawl_external_links.status_code'))
            ->select('crawl_external_refs.id', 'crawl_external_refs.page_id', 'crawl_external_links.url', 'crawl_external_links.status_code')
            ->orderBy('crawl_external_refs.id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $this->emitFor('external_link_broken', $row->page_id, ($row->status_code ? 'HTTP '.$row->status_code : 'Yanıt yok').': '.$row->url);
                }
            });
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

    private function auditBotAccess(): void
    {
        $rows = $this->crawl->bot_access ?? [];
        $home = $this->crawl->pages()->where('depth', 0)->orderBy('id')->first();
        if ($rows === [] || $home === null) {
            return;
        }

        $rules = ['search' => 'search_bot_blocked', 'ai_search' => 'ai_search_bot_blocked', 'ai_training' => 'ai_training_bot_blocked'];
        foreach ($rules as $kind => $rule) {
            $blocked = collect($rows)->where('kind', $kind)->where('robots_allowed', false)->pluck('name');
            if ($blocked->isNotEmpty()) {
                $this->emit($rule, $home, $blocked->implode(', '));
            }
        }

        if ($home->status_code === 200) {
            $refused = collect($rows)->where('fetch', 'refused')->map(fn ($row) => $row['name'].' ('.$row['status'].')');
            if ($refused->isNotEmpty()) {
                $this->emit('bot_fetch_refused', $home, $refused->implode(', '));
            }

            // A bot that gets far less text than the crawler did is shown a challenge page or different content.
            if ($home->word_count >= self::BOT_CONTENT_MIN_WORDS) {
                $thin = collect($rows)->filter(fn ($row) => ($row['words'] ?? null) !== null && $row['words'] < $home->word_count * self::BOT_CONTENT_SHARE)
                    ->map(fn ($row) => $row['name'].' ('.$row['words'].' kelime)');
                if ($thin->isNotEmpty()) {
                    $this->emit('bot_content_differs', $home, "Normal ziyaretçi {$home->word_count} kelime görüyor; ".$thin->implode(', '));
                }
            }
        }
    }

    /**
     * Statuses that say only "this site turns bots away" (login walls, bot protection, LinkedIn's 999), not that the
     * link is broken, so they are not reported.
     */
    private const EXTERNAL_BLOCKED = [401, 403, 429, 999];

    /** Bot content checks need a home page with at least this much text, and flag bots that get under half of it. */
    private const BOT_CONTENT_MIN_WORDS = 100;

    private const BOT_CONTENT_SHARE = 0.5;

    private const ENTITY_TYPES = '/^(Organization|Corporation|LocalBusiness|Person|OnlineBusiness|OnlineStore|Store|Restaurant|ProfessionalService|MedicalOrganization|MedicalBusiness|Dentist|Physician|EducationalOrganization|NewsMediaOrganization|NGO|LegalService|Attorney|HealthAndBeautyBusiness|HomeAndConstructionBusiness|AutomotiveBusiness|FinancialService|RealEstateAgent|TravelAgency|LodgingBusiness|Hotel|SportsActivityLocation|EntertainmentBusiness|FoodEstablishment)$/';

    private function auditAiReadiness(): void
    {
        $home = $this->crawl->pages()->where('depth', 0)->orderBy('id')->first();
        if ($home === null || $home->status_code !== 200) {
            return;
        }

        $types = array_filter(explode(',', (string) $home->schema_types));
        if (! collect($types)->contains(fn ($type) => preg_match(self::ENTITY_TYPES, $type))) {
            $this->emit('home_missing_organization_schema', $home, $types === [] ? 'Şema yok' : 'Bulunan: '.implode(', ', $types));
        }

        $this->auditNap($home);
        $this->auditSeoPluginGraph($home, $types);

        if ($home->same_as === 0 && collect($types)->contains(fn ($type) => preg_match(self::ENTITY_TYPES, $type))) {
            $this->emit('entity_same_as_missing', $home);
        }

        $missing = [];
        foreach (self::TRUST_PAGES as $label => [$title, $urls]) {
            $found = $this->crawl->pages()->where('status_code', 200)->where(function ($query) use ($title, $urls) {
                $query->where('title', 'like', "%{$title}%");
                foreach ($urls as $fragment) {
                    $query->orWhere('url', 'like', "%{$fragment}%");
                }
            })->exists();
            if (! $found) {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $this->emit('trust_pages_missing', $home, 'Bulunamayan: '.implode(', ', $missing));
        }

        $this->auditStaleArticles();

        $llms = $this->crawl->llms_txt['status'] ?? null;
        if ($llms === 'missing') {
            $this->emit('llms_txt_missing', $home);
        } elseif ($llms === 'invalid') {
            $this->emit('llms_txt_invalid', $home);
        }
    }

    private function auditPageSpeed(): void
    {
        $results = collect($this->crawl->pagespeed ?? [])->where('state', 'done');
        if ($results->isEmpty()) {
            return;
        }

        $pageIds = $this->crawl->pages()->whereIn('url_hash', $results->map(fn (array $r) => sha1($r['url']))->all())->pluck('id', 'url_hash');
        foreach ($results as $result) {
            $pageId = $pageIds[sha1($result['url'])] ?? null;
            if ($pageId === null) {
                continue;
            }

            if ($result['field'] ?? null) {
                $ratings = PageSpeed::ratings($result['field']);
                $failing = array_filter($ratings, fn (string $rating) => $rating !== 'good');
                if ($failing !== []) {
                    $labels = collect($failing)->map(fn ($rating, $metric) => PageSpeed::label($metric, $result['field'][$metric]))->implode(', ');
                    $this->emitFor(in_array('poor', $failing, true) ? 'cwv_poor' : 'cwv_needs_improvement', $pageId, $labels.' (gerçek kullanıcılar, mobil)');
                }
            }
            if (($result['score'] ?? null) !== null && $result['score'] < PageSpeed::POOR_SCORE) {
                $lab = collect($result['lab'] ?? [])->filter(fn ($value) => $value !== null)->map(fn ($value, $metric) => PageSpeed::label($metric, $value))->implode(', ');
                $this->emitFor('pagespeed_low_score', $pageId, "Mobil puan {$result['score']}/100".($lab ? " · {$lab}" : ''));
            }
        }
    }

    /** Trust pages, found by a title fragment or one of the URL fragments. */
    /** Texts at least this long should break information into lists or tables. */
    private const LIST_WORDS = 800;

    /** Articles at least this long should link to at least one outside source. */
    private const SOURCE_WORDS = 500;

    private const TRUST_PAGES = [
        'hakkımızda' => ['Hakkımızda', ['hakkimizda', 'hakkinda', 'about', 'kurumsal', 'biz-kimiz']],
        'iletişim' => ['İletişim', ['iletisim', 'contact', 'bize-ulasin']],
        'gizlilik / KVKK' => ['Gizlilik', ['gizlilik', 'kvkk', 'privacy', 'cerez', 'cookie']],
    ];

    /**
     * Articles not updated for a year that still bring Search Console clicks: worth refreshing before they slip.
     */
    private function auditStaleArticles(): void
    {
        if ($this->staleArticles === [] || blank($this->crawl->project->gsc_property)) {
            return;
        }

        $clicks = DB::table('gsc_page_days')->where('project_id', $this->crawl->project_id)->where('day', '>=', now()->subDays(28)->toDateString())
            ->whereIn('page_hash', array_column($this->staleArticles, 0))->groupBy('page_hash')->selectRaw('page_hash, SUM(clicks) as clicks')->pluck('clicks', 'page_hash');
        foreach ($this->staleArticles as $pageId => [$hash, $date]) {
            if (($clicks[$hash] ?? 0) > 0) {
                $this->emitFor('stale_content', $pageId, "Son güncelleme {$date}, son 28 günde {$clicks[$hash]} tıklama");
            }
        }
    }

    private function auditWordPress(): void
    {
        $home = $this->crawl->pages()->where('depth', 0)->orderBy('id')->first();
        if ($home === null) {
            return;
        }

        if ($home->status_code === 200 && str_contains(strtolower((string) $home->meta_robots.' '.$home->x_robots), 'noindex')) {
            $this->emit('wp_search_engines_discouraged', $home);
        }

        $defaults = '/^(Merhaba dünya!|Hello world!|Örnek sayfa|Sample Page)( [|–-] |$)|Bir başka WordPress sitesi|Just another WordPress site/u';
        $this->crawl->pages()->where('status_code', 200)->whereNotNull('title')->select('id', 'title')->chunkById(500, function ($pages) use ($defaults) {
            foreach ($pages as $page) {
                if (preg_match($defaults, $page->title)) {
                    $this->emitFor('wp_default_content', $page->id, $page->title);
                }
            }
        });

        foreach ($this->crawl->pages()->where(fn ($q) => $q->where('url', 'like', '%/category/uncategorized/%')->orWhere('url', 'like', '%/kategorilenmemis/%'))->where('status_code', 200)->limit(1)->get() as $page) {
            $this->emit('wp_uncategorized', $page);
        }
        foreach ($this->crawl->pages()->where('url', 'like', '%replytocom=%')->select('id', 'url')->get() as $page) {
            $this->emitFor('wp_replytocom', $page->id);
        }

        $checks = $this->crawl->wp_checks ?? [];
        $probes = $checks['probes'] ?? [];
        if ($probes === []) {
            return;
        }
        $found = fn (string $key): bool => (bool) ($probes[$key]['finding'] ?? false);
        $value = fn (string $key) => $probes[$key]['value'] ?? null;

        $version = $value('feed') ?? $value('readme');
        $latest = $value('core_latest');
        if ($version) {
            $this->emit('wp_version_exposed', $home, 'Sürüm '.$version);
            if ($latest && version_compare($version, $latest, '<')) {
                $this->emit('wp_core_outdated', $home, "Sitede {$version}, güncel {$latest}");
            }
        }

        $plugins = $checks['inventory']['plugins'] ?? [];
        $themes = $checks['inventory']['themes'] ?? [];
        if ($plugins !== [] || $themes !== []) {
            $this->emit('wp_inventory', $home, 'Eklentiler: '.(implode(', ', array_keys($plugins)) ?: '—').' · Temalar: '.(implode(', ', array_keys($themes)) ?: '—'));
        }
        $outdated = [];
        foreach ($plugins as $slug => $seen) {
            $current = $value('plugin:'.$slug);
            // An asset ?ver= equal to the core version is WordPress's own default, not the plugin's.
            if ($seen && $current && $seen !== $version && version_compare($seen, $current, '<')) {
                $outdated[] = "{$slug} {$seen} → {$current}";
            }
        }
        if ($outdated !== []) {
            $this->emit('wp_plugin_outdated', $home, implode(', ', $outdated));
        }

        if ($found('rest_users') || $found('author_redirect')) {
            $this->emit('wp_user_enumeration', $home, 'Görünen: '.collect([$value('rest_users'), $value('author_redirect')])->filter()->unique()->implode(', '));
        }
        if ($found('xmlrpc')) {
            $this->emit('wp_xmlrpc_enabled', $home);
        }
        $files = array_keys(array_filter(['readme.html' => $found('readme'), 'license.txt' => $found('license')]));
        if ($files !== []) {
            $this->emit('wp_default_files', $home, implode(', ', $files));
        }
        if ($found('debug_log')) {
            $this->emit('wp_debug_log_public', $home, '/wp-content/debug.log');
        }
        $listings = array_keys(array_filter(['/wp-content/uploads/' => $found('uploads_index'), '/wp-content/plugins/' => $found('plugins_index')]));
        if ($listings !== []) {
            $this->emit('wp_directory_listing', $home, implode(', ', $listings));
        }
        $backups = collect($probes)->filter(fn ($probe, $key) => str_starts_with($key, 'backup:') && ($probe['finding'] ?? false))->keys()->map(fn ($key) => '/'.substr($key, 7));
        if ($backups->isNotEmpty()) {
            $this->emit('wp_config_backup_exposed', $home, $backups->implode(', '));
        }
        if ($found('login_http')) {
            $this->emit('wp_login_http', $home);
        }
        if ($found('search')) {
            $this->emit('wp_search_indexable', $home, '/?s=…');
        }
        if ($found('wp_sitemap') && $found('sitemap_index')) {
            $this->emit('wp_double_sitemap', $home, '/wp-sitemap.xml + /sitemap_index.xml');
        }
    }

    private function storeAiScores(): void
    {
        $this->crawl->pages()->whereNotNull('ai_score')->update(['ai_score' => null]);
        foreach ($this->aiScores as $score => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('crawl_pages')->whereIn('id', $chunk)->update(['ai_score' => $score]);
            }
        }
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
