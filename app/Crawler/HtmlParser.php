<?php

namespace App\Crawler;

use App\Audit\Readability;
use App\Audit\SchemaRules;
use App\Audit\SchemaVocabulary;
use App\Audit\SimHash;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;

class HtmlParser
{
    /** Below this many words in the raw HTML, a JavaScript app shell has no readable content without rendering. */
    private const JS_SHELL_WORDS = 50;

    /** Longer paragraphs are hard to lift as a self-contained answer passage. */
    public const LONG_PARAGRAPH_WORDS = 150;

    private const QUESTION_START = '/^(nasıl|nedir|neden|niçin|niye|ne zaman|nerede|hangi|kim|kaç|ne kadar|ne |nelerdir|how|what|why|when|where|which|who|can|does|is|are|should)\b/u';

    private const ARTICLE_TYPES = ['Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report', 'MedicalWebPage'];

    private const ENTITY_TYPES = ['Organization', 'Corporation', 'LocalBusiness', 'Person', 'MedicalOrganization', 'MedicalBusiness', 'Dentist', 'Physician', 'MedicalClinic', 'ProfessionalService', 'Store', 'OnlineStore', 'Restaurant', 'LegalService', 'Attorney', 'EducationalOrganization'];

    /** Link texts that say nothing about the target page (Google: "write good anchor text", Lighthouse "link-text" audit). */
    public const GENERIC_ANCHORS = [
        'buraya tıklayın', 'buraya tıklayınız', 'buraya tıkla', 'tıklayın', 'tıklayınız', 'tıkla', 'buraya', 'burada', 'burası',
        'devamı', 'devamını oku', 'devamını okuyun', 'devamını okuyunuz', 'devamı için tıklayın', 'daha fazla', 'daha fazlası',
        'daha fazla bilgi', 'detay', 'detaylar', 'detaylı bilgi', 'ayrıntılar', 'incele', 'göz at', 'link', 'bağlantı', 'git',
        'click here', 'click', 'here', 'read more', 'more', 'learn more', 'more info', 'details', 'this', 'this page', 'go',
        'continue', 'right here',
    ];

    /**
     * @return array{title: ?string, description: ?string, meta_robots: ?string, canonical: ?string, canonical_conflict: bool, h2_count: int, heading_skip: ?string, hreflang: array<int, array{lang: string, url: string}>, pagination: array{next: ?string, prev: ?string}, h1: array<int, string>, word_count: int, content_hash: string, simhash: ?string, links: array<int, array{url: string, nofollow: bool}>, uncrawlable_links: int, empty_anchors: int, generic_anchors: array<int, string>, generic_anchor_count: int, meta_refresh: ?string, generator: ?string, wordpress_hint: bool, images_missing_alt: int, images_missing_size: int, images: array<int, string>, mixed_content: int, insecure_forms: int, page_type: ?string, seo_plugin: ?string, schema_types: array<int, string>, schema_errors: int, schema_problems: array<int, array{type: string, required: array<int, string>, recommended: array<int, string>}>, schema_duplicates: array<int, string>, schema_mismatch: array<int, string>, entity: ?array{name: ?string, phone: ?string, logo: bool, address: ?string}, schema_vocabulary: array<int, string>, js_shell: bool, wp_type: ?string, wp_category: ?string, question_headings: int, long_paragraphs: int, readability: ?int, aeo: array{questions: int, answered: int, lists: int, tables: int, faq: bool, sources: int, numbers: int, quotes: int}, is_article: bool, published_at: ?string, modified_at: ?string, has_author: bool, same_as: int, author_url: ?string, person_schema: bool}
     */
    public static function parse(string $html, string $pageUrl): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        $title = self::text($xpath->query('//title')->item(0)?->textContent);
        $description = self::meta($xpath, 'description');
        $robots = self::meta($xpath, 'robots');
        $generator = self::meta($xpath, 'generator');

        $canonical = null;
        $canonicals = [];
        $hreflang = [];
        $pagination = ['next' => null, 'prev' => null];
        foreach ($xpath->query('//link[@href]') as $link) {
            $rel = preg_split('/\s+/', strtolower($link->getAttribute('rel')));
            if (in_array('canonical', $rel, true)) {
                $canonicals[] = UrlNormalizer::resolve($link->getAttribute('href'), $pageUrl);
                $canonical ??= end($canonicals);
            } elseif (in_array('alternate', $rel, true) && trim($link->getAttribute('hreflang')) !== '') {
                $url = UrlNormalizer::resolve($link->getAttribute('href'), $pageUrl);
                if ($url !== null && count($hreflang) < 100) {
                    $hreflang[] = ['lang' => trim($link->getAttribute('hreflang')), 'url' => $url];
                }
            } elseif (in_array('next', $rel, true) || in_array('prev', $rel, true) || in_array('previous', $rel, true)) {
                $key = in_array('next', $rel, true) ? 'next' : 'prev';
                $pagination[$key] ??= UrlNormalizer::resolve($link->getAttribute('href'), $pageUrl);
            }
        }

        $h1 = [];
        foreach ($xpath->query('//h1') as $node) {
            $h1[] = self::text($node->textContent) ?? '';
        }

        // Heading outline: going down more than one level (H2 straight to H4) or opening with a lower heading than H1.
        $levels = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
            $levels[] = (int) substr($node->nodeName, 1);
        }
        $headingSkip = null;
        foreach ($levels as $i => $level) {
            // The first heading may be an H2 (themes often put the H1 lower down or leave it out; h1_missing covers that).
            $previous = $i === 0 ? 1 : $levels[$i - 1];
            if ($level > $previous + 1) {
                $headingSkip = ($i === 0 ? 'başta' : 'H'.$previous).' → H'.$level;
                break;
            }
        }

        $metaRefresh = null;
        foreach ($xpath->query('//meta[@http-equiv]') as $node) {
            if (strtolower($node->getAttribute('http-equiv')) === 'refresh') {
                $metaRefresh = $node->getAttribute('content');
            }
        }

        $links = [];
        $emptyAnchors = 0;
        $genericAnchors = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $url = UrlNormalizer::resolve($a->getAttribute('href'), $pageUrl);
            if ($url !== null) {
                $links[] = ['url' => $url, 'nofollow' => str_contains(strtolower($a->getAttribute('rel')), 'nofollow')];

                $anchor = self::anchorText($xpath, $a);
                if ($anchor === '') {
                    $emptyAnchors++;
                } elseif (in_array(preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', mb_strtolower($anchor)), self::GENERIC_ANCHORS, true)) {
                    $genericAnchors[] = $anchor;
                }
            }
        }

        $uncrawlableLinks = 0;
        foreach ($xpath->query('//a[starts-with(normalize-space(translate(@href, "JAVSCRIPT", "javscript")), "javascript:")]') as $a) {
            // javascript:void(0) and javascript:; are menu toggles, not links to content.
            if (! preg_match('/^javascript:\s*(void\s*\(?\s*0\s*\)?\s*;?|;|)\s*$/i', trim($a->getAttribute('href')))) {
                $uncrawlableLinks++;
            }
        }
        foreach ($xpath->query('//a[not(@href) or normalize-space(@href)="" or normalize-space(@href)="#"][@onclick]') as $a) {
            if (preg_match('#location|window\.open|https?:|[\'"]/#i', $a->getAttribute('onclick'))) {
                $uncrawlableLinks++;
            }
        }

        $imagesMissingAlt = $xpath->query('//img[not(@alt)]')->length;
        // Without width and height the browser can't reserve space, so the layout shifts (CLS) when the image loads.
        $imagesMissingSize = $xpath->query('//img[not(@width) or not(@height)]')->length;

        $images = [];
        foreach ($xpath->query('//img/@src') as $attribute) {
            $src = UrlNormalizer::resolve($attribute->nodeValue, $pageUrl);
            if ($src !== null && ! in_array($src, $images, true)) {
                $images[] = $src;
            }
        }

        $mixedContent = 0;
        if (str_starts_with($pageUrl, 'https://')) {
            foreach ($xpath->query('//img/@src|//script/@src|//iframe/@src|//source/@src|//video/@src|//audio/@src|//link[@rel="stylesheet"]/@href') as $attribute) {
                if (stripos(trim($attribute->nodeValue), 'http://') === 0) {
                    $mixedContent++;
                }
            }
        }

        // Forms whose data would travel unencrypted: posted to an http:// address, or a password field on an http page.
        $insecureForms = 0;
        foreach ($xpath->query('//form') as $form) {
            $action = trim($form->getAttribute('action'));
            $target = $action === '' ? $pageUrl : (UrlNormalizer::resolve($action, $pageUrl) ?? '');
            $password = $xpath->query('.//input[translate(@type, "PASSWORD", "password")="password"]', $form)->length > 0;
            if (stripos($target, 'http://') === 0 && (str_starts_with($pageUrl, 'https://') || $password)) {
                $insecureForms++;
            }
        }

        $bodyClass = ' '.strtolower((string) $xpath->query('//body/@class')->item(0)?->nodeValue).' ';
        $pageType = match (true) {
            str_contains($bodyClass, ' attachment ') || str_contains($bodyClass, ' attachment-') => 'attachment',
            str_contains($bodyClass, ' archive ') => 'archive',
            default => null,
        };

        [$schemaTypes, $schemaErrors, $schemaProblems, $schemaDuplicates] = self::jsonLd($xpath);
        [$wpType, $wpCategory] = self::wordPressContent($xpath, $bodyClass);
        $authority = self::authority($xpath, $bodyClass);
        $pageDates = self::pageDates($xpath);
        $ldBlocks = [];
        foreach ($xpath->query('//script[@type]') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) === 'application/ld+json' && is_array($block = json_decode(trim($script->textContent), true))) {
                $ldBlocks[] = $block;
            }
        }

        // Answer-engine signals: headings phrased as questions, and paragraphs too long to quote as a passage.
        $questionHeadings = 0;
        foreach ($xpath->query('//h2|//h3') as $node) {
            $heading = mb_strtolower(self::text($node->textContent) ?? '');
            if (str_ends_with($heading, '?') || preg_match(self::QUESTION_START, $heading)) {
                $questionHeadings++;
            }
        }
        $longParagraphs = 0;
        foreach ($xpath->query('//p') as $node) {
            if (preg_match_all('/[\p{L}\p{N}]+/u', $node->textContent) > self::LONG_PARAGRAPH_WORDS) {
                $longParagraphs++;
            }
        }

        $seoPlugins = [];
        foreach (['yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math', 'aioseo' => 'All in One SEO', 'seopress' => 'wp-seopress', 'tsf' => 'The SEO Framework'] as $key => $needle) {
            if (stripos($html, $needle) !== false) {
                $seoPlugins[] = $key;
            }
        }

        // An empty app mount point or an "enable JavaScript" notice means the content is drawn by JavaScript.
        $spaRoot = $xpath->query('//*[@id="root" or @id="app" or @id="__next" or @id="__nuxt" or @id="___gatsby"][not(normalize-space())][not(.//img)]')->length > 0;
        $noscriptJs = false;
        foreach ($xpath->query('//noscript') as $node) {
            $noscriptJs = $noscriptJs || stripos($node->textContent, 'javascript') !== false;
        }

        foreach ($xpath->query('//script|//style|//noscript|//head') as $node) {
            $node->parentNode?->removeChild($node);
        }
        // Join text nodes with a space so words in adjacent elements (</h1><p>) are not glued together.
        $texts = [];
        foreach ($xpath->query('//text()') as $node) {
            $texts[] = $node->nodeValue;
        }
        $bodyText = preg_replace('/\s+/u', ' ', implode(' ', $texts));
        $schemaMismatch = self::schemaConsistency($ldBlocks, $title, $h1[0] ?? null, $bodyText, $pageDates);
        $entity = self::entity($ldBlocks);
        $schemaVocabulary = SchemaVocabulary::problems($ldBlocks);
        $wordCount = (int) preg_match_all('/[\p{L}\p{N}]+/u', $bodyText);

        // The near-duplicate fingerprint uses the main text only, so a shared menu and footer don't make pages look alike.
        foreach ($xpath->query('//nav|//header|//footer|//aside|//form') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $mainText = [];
        foreach ($xpath->query('//text()') as $node) {
            $mainText[] = $node->nodeValue;
        }
        $aeo = self::answerSignals($xpath, $pageUrl, $html);

        // Readability is measured on running text (paragraphs and list items), where sentences end with punctuation.
        $prose = [];
        foreach ($xpath->query('//p|//li[not(.//p)]') as $node) {
            $sentence = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            $prose[] = preg_match('/[.!?…:;]$/u', $sentence) ? $sentence : $sentence.'.';
        }
        $prose = implode(' ', $prose);
        $readability = Readability::isTurkish($xpath->query('//html/@lang')->item(0)?->nodeValue, $prose) ? Readability::atesman($prose) : null;

        $wordpress = (bool) preg_match('#/wp-content/|/wp-json/|/wp-includes/#', $html)
            || ($generator !== null && stripos($generator, 'wordpress') !== false);

        return [
            'title' => $title,
            'description' => $description,
            'meta_robots' => $robots,
            'canonical' => $canonical,
            'canonical_conflict' => count(array_unique($canonicals)) > 1,
            'h2_count' => count(array_keys($levels, 2, true)),
            'heading_skip' => $headingSkip,
            'hreflang' => $hreflang,
            'pagination' => $pagination,
            'h1' => $h1,
            'word_count' => $wordCount,
            'content_hash' => md5(trim($bodyText)),
            'simhash' => SimHash::of(implode(' ', $mainText)),
            'links' => $links,
            'uncrawlable_links' => $uncrawlableLinks,
            'empty_anchors' => $emptyAnchors,
            'generic_anchors' => array_values(array_unique($genericAnchors)),
            'generic_anchor_count' => count($genericAnchors),
            'meta_refresh' => $metaRefresh,
            'generator' => $generator,
            'images_missing_alt' => $imagesMissingAlt,
            'images_missing_size' => $imagesMissingSize,
            'images' => $images,
            'mixed_content' => $mixedContent,
            'insecure_forms' => $insecureForms,
            'page_type' => $pageType,
            'seo_plugin' => $seoPlugins === [] ? null : implode(',', $seoPlugins),
            'schema_types' => $schemaTypes,
            'schema_errors' => $schemaErrors,
            'schema_problems' => $schemaProblems,
            'schema_duplicates' => $schemaDuplicates,
            'schema_mismatch' => $schemaMismatch,
            'entity' => $entity,
            'schema_vocabulary' => $schemaVocabulary,
            'js_shell' => $wordCount < self::JS_SHELL_WORDS && ($spaRoot || $noscriptJs),
            'wordpress_hint' => $wordpress,
            'question_headings' => $questionHeadings,
            'long_paragraphs' => $longParagraphs,
            'readability' => $readability,
            'aeo' => $aeo,
            ...$authority,
            'wp_type' => $wordpress ? $wpType : null,
            'wp_category' => $wordpress ? $wpCategory : null,
        ];
    }

    /**
     * Structured data on the page: schema.org types from JSON-LD (including @graph and nested items), Microdata and RDFa,
     * the number of JSON-LD blocks that are not valid JSON, entities missing rich-result properties, and types printed
     * more than once (usually two plugins doing the same job).
     *
     * @return array{0: array<int, string>, 1: int, 2: array<int, array{type: string, required: array<int, string>, recommended: array<int, string>}>, 3: array<int, string>}
     */
    private static function jsonLd(DOMXPath $xpath): array
    {
        $types = [];
        $errors = 0;
        $problems = [];
        $topLevel = [];

        foreach ($xpath->query('//script[@type]') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }

            $data = json_decode(trim($script->textContent), true);
            if (! is_array($data)) {
                $errors++;

                continue;
            }

            // Roots: the block itself, a list of nodes, or the nodes of its @graph.
            $roots = array_is_list($data) ? $data : (isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data]);
            foreach ($roots as $root) {
                if (is_array($root)) {
                    foreach ((array) ($root['@type'] ?? []) as $type) {
                        $topLevel[] = SchemaRules::ruleType(SchemaRules::shortType((string) $type));
                    }
                }
            }

            // Recommended properties are only asked of the page's main entities: a nested publisher or author node
            // naturally carries just a name and a logo.
            $walk = function (array $node, bool $top) use (&$walk, &$types, &$problems): void {
                if (isset($node['@type'])) {
                    foreach ((array) $node['@type'] as $type) {
                        $types[] = SchemaRules::shortType((string) $type);
                    }
                    // A bare reference ({"@id": …} with a type) points at a node checked elsewhere.
                    $problem = count(array_diff(array_keys($node), ['@type', '@id', '@context'])) > 0 ? SchemaRules::check($node) : null;
                    if ($problem && ! $top) {
                        $problem['recommended'] = [];
                    }
                    if ($problem && ($problem['required'] !== [] || $problem['recommended'] !== [])) {
                        $problems[$problem['type'].'|'.implode(',', $problem['required']).'|'.implode(',', $problem['recommended'])] = $problem;
                    }
                }
                foreach ($node as $key => $value) {
                    if (is_array($value) && $key !== '@graph') {
                        $walk($value, false);
                    }
                }
            };
            foreach ($roots as $root) {
                if (is_array($root)) {
                    $walk($root, true);
                }
            }
        }

        foreach ($xpath->query('//*[@itemscope][@itemtype]') as $item) {
            foreach (preg_split('/\s+/', trim($item->getAttribute('itemtype'))) as $type) {
                if (str_contains($type, 'schema.org/')) {
                    $types[] = SchemaRules::shortType(rtrim($type, '/'));
                }
            }
        }

        // RDFa Lite: typeof="Article" under vocab="https://schema.org/", or a full or schema:-prefixed type name.
        foreach ($xpath->query('//*[@typeof]') as $item) {
            $vocab = $xpath->query('ancestor-or-self::*[@vocab][1]/@vocab', $item)->item(0)?->nodeValue ?? '';
            foreach (preg_split('/\s+/', trim($item->getAttribute('typeof'))) as $type) {
                $type = match (true) {
                    str_contains($type, 'schema.org/') => SchemaRules::shortType(rtrim($type, '/')),
                    str_starts_with($type, 'schema:') => substr($type, 7),
                    ! str_contains($type, ':') && str_contains($vocab, 'schema.org') => $type,
                    default => '',
                };
                if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $type)) {
                    $types[] = $type;
                }
            }
        }

        $duplicates = array_keys(array_filter(array_count_values(array_filter($topLevel, fn (?string $type) => $type !== null && in_array($type, SchemaRules::SINGLE, true))), fn (int $n) => $n > 1));

        return [array_values(array_unique($types)), $errors, array_values($problems), $duplicates];
    }

    /**
     * WordPress content type and category from the classes themes print with body_class() and post_class():
     * "single single-post" → post, "page page-id-5" → page, "single-product" → product, "category category-news" → category archive.
     * The category is the first category-{slug} (or WooCommerce product_cat-{slug}) on the post, or the archive's own slug.
     *
     * @return array{0: ?string, 1: ?string}
     */
    /**
     * E-E-A-T and entity signals: is this an article, when was it published and updated, is an author shown,
     * and how many sameAs profiles does the site's Organization/Person entity link to.
     *
     * @return array{is_article: bool, published_at: ?string, modified_at: ?string, has_author: bool, same_as: int, author_url: ?string, person_schema: bool}
     */
    private static function authority(DOMXPath $xpath, string $bodyClass): array
    {
        $isArticle = str_contains($bodyClass, ' single-post ');
        $published = $modified = null;
        $hasAuthor = false;
        $sameAs = 0;
        $hasPerson = false;
        $authorRefs = [];
        $persons = [];

        $walk = function ($node, bool $topLevel) use (&$walk, &$isArticle, &$published, &$modified, &$hasAuthor, &$sameAs, &$hasPerson, &$authorRefs, &$persons): void {
            if (! is_array($node)) {
                return;
            }
            if (array_is_list($node)) {
                foreach ($node as $item) {
                    $walk($item, $topLevel);
                }

                return;
            }
            $types = array_map(fn ($type) => is_string($type) ? preg_replace('#^https?://schema\.org/#', '', $type) : '', (array) ($node['@type'] ?? []));
            if (array_intersect($types, self::ARTICLE_TYPES) !== []) {
                $isArticle = true;
                $published ??= is_string($node['datePublished'] ?? null) ? $node['datePublished'] : null;
                $modified ??= is_string($node['dateModified'] ?? null) ? $node['dateModified'] : null;
                $hasAuthor = $hasAuthor || ! empty($node['author']);
                foreach (is_array($node['author'] ?? null) ? (array_is_list($node['author']) ? $node['author'] : [$node['author']]) : [] as $author) {
                    if (is_array($author)) {
                        $authorRefs[] = $author;
                    }
                }
            }
            if (array_intersect($types, ['Person', 'ProfilePage']) !== []) {
                $hasPerson = true;
                if (is_string($node['@id'] ?? null)) {
                    $persons[$node['@id']] = $node;
                }
            }
            if ($topLevel && array_intersect($types, self::ENTITY_TYPES) !== []) {
                $sameAs = max($sameAs, count(array_filter((array) ($node['sameAs'] ?? []), 'is_string')));
            }
            if (isset($node['@graph'])) {
                $walk($node['@graph'], true);
            }
            foreach (['mainEntity', 'mainEntityOfPage'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    $walk($node[$key], false);
                }
            }
        };
        foreach ($xpath->query('//script[@type]') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) === 'application/ld+json') {
                $walk(json_decode(trim($script->textContent), true), true);
            }
        }

        $meta = function (string $property) use ($xpath): ?string {
            foreach ($xpath->query('//meta[@property or @name]') as $node) {
                if (strtolower($node->getAttribute('property') ?: $node->getAttribute('name')) === $property) {
                    return trim($node->getAttribute('content')) ?: null;
                }
            }

            return null;
        };
        $isArticle = $isArticle || $meta('og:type') === 'article';
        $published ??= $meta('article:published_time') ?? $xpath->query('//time[@datetime]')->item(0)?->getAttribute('datetime');
        $modified ??= $meta('article:modified_time') ?? $meta('og:updated_time');
        $hasAuthor = $hasAuthor || $meta('author') !== null || $meta('article:author') !== null
            || $xpath->query('//a[contains(concat(" ", normalize-space(@rel), " "), " author ")] | //*[contains(concat(" ", normalize-space(@class), " "), " author ") or contains(@class, "byline") or contains(@class, "author-name") or contains(@class, "entry-author")]')->length > 0;

        $date = fn (?string $value) => $value === null ? null : rescue(fn () => Carbon::parse($value)->toDateString(), null, false);

        // The author's own page: a rel="author" link, the url of the article's author in the schema (directly or
        // through its @id in the graph, as Yoast and Rank Math print it), or a WordPress /author/ archive link.
        $authorUrl = $xpath->query('//a[contains(concat(" ", normalize-space(@rel), " "), " author ")]/@href')->item(0)?->nodeValue;
        foreach ($authorRefs as $author) {
            $authorUrl ??= is_string($author['url'] ?? null) ? $author['url'] : null;
            $authorUrl ??= is_string($persons[$author['@id'] ?? '']['url'] ?? null) ? $persons[$author['@id']]['url'] : null;
        }
        $authorUrl ??= $xpath->query('//a[contains(@href, "/author/")]/@href')->item(0)?->nodeValue;

        return ['is_article' => $isArticle, 'published_at' => $date($published), 'modified_at' => $date($modified), 'has_author' => $hasAuthor, 'same_as' => $sameAs,
            'author_url' => $authorUrl, 'person_schema' => $hasPerson];
    }

    /**
     * Structured data that disagrees with the visible page: an article headline or product name unlike the page's
     * title and H1, an offer price that appears nowhere on the page, article dates that disagree with the page, or an @id reference to a node that is not there.
     * Google's structured data guidelines require the markup to describe content visible to users.
     *
     * @param  array<int, array<mixed>>  $blocks  decoded JSON-LD blocks
     * @return array<int, string>
     */
    private static function schemaConsistency(array $blocks, ?string $title, ?string $h1, string $bodyText, array $pageDates = []): array
    {
        $headings = array_filter([$title, $h1]);
        $problems = [];
        $defined = [];
        $referenced = [];
        $prices = self::visibleNumbers($bodyText);
        $digits = preg_replace('/\D/', '', $bodyText);

        $walk = function ($node) use (&$walk, &$problems, &$defined, &$referenced, $headings, $prices, $digits, $pageDates): void {
            if (! is_array($node)) {
                return;
            }
            if (array_is_list($node)) {
                array_walk($node, fn ($item) => $walk($item));

                return;
            }
            if (is_string($node['@id'] ?? null)) {
                if (count($node) === 1) {
                    $referenced[$node['@id']] = true;
                } else {
                    $defined[$node['@id']] = true;
                }
            }
            $types = array_map(fn ($type) => is_string($type) ? preg_replace('#^https?://schema\.org/#', '', $type) : '', (array) ($node['@type'] ?? []));
            $name = match (true) {
                array_intersect($types, self::ARTICLE_TYPES) !== [] => $node['headline'] ?? null,
                in_array('Product', $types, true) => $node['name'] ?? null,
                default => null,
            };
            if (is_string($name) && $headings !== [] && ! collect($headings)->contains(fn (string $heading) => self::similar($name, $heading))) {
                $problems[] = 'Şemadaki başlık sayfada yok: "'.mb_substr($name, 0, 60).'"';
            }
            if (array_intersect($types, ['Offer', 'AggregateOffer']) !== []) {
                foreach (['price', 'lowPrice'] as $key) {
                    $price = is_numeric($node[$key] ?? null) ? (float) $node[$key] : null;
                    if ($price !== null && $price > 0 && ! in_array(round($price, 2), $prices, true)) {
                        $problems[] = "Şemadaki fiyat ({$node[$key]}) sayfada görünmüyor";
                    }
                }
            }
            if (array_intersect($types, self::ARTICLE_TYPES) !== []) {
                array_push($problems, ...self::dateProblems($node, $pageDates));
            }
            $phone = self::phone($node['telephone'] ?? null);
            if ($phone !== null && ! str_contains($digits, $phone)) {
                $problems[] = 'Şemadaki telefon ('.$node['telephone'].') sayfada görünmüyor';
            }
            foreach ($node as $key => $value) {
                if (is_array($value) && $key !== '@context') {
                    $walk($value);
                }
            }
        };
        array_walk($blocks, fn ($block) => $walk($block));

        // The same main entity printed twice (usually a theme and an SEO plugin) with different names: Google may pick either.
        $names = [];
        foreach ($blocks as $block) {
            $roots = array_is_list($block) ? $block : (isset($block['@graph']) && is_array($block['@graph']) ? $block['@graph'] : [$block]);
            foreach ($roots as $root) {
                $name = is_array($root) ? ($root['name'] ?? $root['headline'] ?? null) : null;
                foreach (is_string($name) && trim($name) !== '' ? (array) ($root['@type'] ?? []) : [] as $type) {
                    $type = SchemaRules::ruleType(SchemaRules::shortType((string) $type));
                    if ($type !== null && in_array($type, SchemaRules::SINGLE, true)) {
                        $names[$type][mb_strtolower(trim($name))] ??= trim($name);
                    }
                }
            }
        }
        foreach ($names as $type => $variants) {
            if (count($variants) > 1) {
                $problems[] = "İki {$type} şeması farklı ad veriyor: ".implode(' / ', array_map(fn (string $name) => '"'.mb_substr($name, 0, 40).'"', array_slice($variants, 0, 2)));
            }
        }

        foreach (array_keys(array_diff_key($referenced, $defined)) as $id) {
            // References to the site-wide nodes of another page's graph (e.g. https://site/#organization) are normal.
            if (str_starts_with($id, '#')) {
                $problems[] = "Kırık @id bağlantısı: {$id}";
            }
        }

        return array_slice(array_values(array_unique($problems)), 0, 5);
    }

    /**
     * Publication dates the page states outside the JSON-LD: the article:published_time / article:modified_time meta
     * tags and the first <time datetime> in the body (the date most themes print under the headline).
     *
     * @return array{published: ?Carbon, modified: ?Carbon}
     */
    private static function pageDates(DOMXPath $xpath): array
    {
        $meta = fn (string $property) => $xpath->query('//meta[@property="'.$property.'" or @name="'.$property.'"]/@content')->item(0)?->nodeValue;
        $parse = fn (?string $value) => blank($value) ? null : rescue(fn () => Carbon::parse(trim($value)), null, false);

        return [
            'published' => $parse($meta('article:published_time')) ?? $parse($xpath->query('//body//time[@datetime]/@datetime')->item(0)?->nodeValue),
            'modified' => $parse($meta('article:modified_time')),
        ];
    }

    /**
     * An article's datePublished / dateModified that contradict the dates on the page, run backwards or lie in the
     * future. Google uses these dates in results, so a wrong one can show readers an outdated or impossible date.
     * A day of slack absorbs time zone differences.
     *
     * @param  array<string, mixed>  $node
     * @param  array{published?: ?Carbon, modified?: ?Carbon}  $pageDates
     * @return array<int, string>
     */
    private static function dateProblems(array $node, array $pageDates): array
    {
        $parse = fn ($value) => is_string($value) && $value !== '' ? rescue(fn () => Carbon::parse($value), null, false) : null;
        $published = $parse($node['datePublished'] ?? null);
        $modified = $parse($node['dateModified'] ?? null);
        $apart = fn (Carbon $a, Carbon $b) => abs($a->getTimestamp() - $b->getTimestamp()) > 86400;
        $day = fn (Carbon $date) => $date->format('d.m.Y');
        $problems = [];

        if ($published && ($page = $pageDates['published'] ?? null) && $apart($published, $page)) {
            $problems[] = 'Şemadaki yayın tarihi ('.$day($published).') sayfadakinden ('.$day($page).') farklı';
        }
        if ($modified && ($page = $pageDates['modified'] ?? null) && $apart($modified, $page)) {
            $problems[] = 'Şemadaki güncelleme tarihi ('.$day($modified).') sayfadakinden ('.$day($page).') farklı';
        }
        if ($published && $modified && $modified->getTimestamp() < $published->getTimestamp() - 86400) {
            $problems[] = 'Şemada güncelleme tarihi ('.$day($modified).') yayın tarihinden ('.$day($published).') önce';
        }
        if ($published && $published->getTimestamp() > now()->getTimestamp() + 86400) {
            $problems[] = 'Şemadaki yayın tarihi ('.$day($published).') ileri bir tarih';
        }

        return $problems;
    }

    /**
     * How quotable the main content is for answer engines (Google AI Overviews, ChatGPT, Perplexity): question
     * headings followed straight away by a short answer paragraph (20-60 words), lists and tables, an FAQ section,
     * links to outside sources, sentences with figures and quotations (expert or source quotes in blockquote/q). Runs on the main content (menus and footer removed).
     *
     * @return array{questions: int, answered: int, lists: int, tables: int, faq: bool, sources: int, numbers: int, quotes: int}
     */
    private static function answerSignals(DOMXPath $xpath, string $pageUrl, string $html): array
    {
        $questions = 0;
        $answered = 0;
        foreach ($xpath->query('//h2|//h3') as $heading) {
            $text = mb_strtolower(self::text($heading->textContent) ?? '');
            if (! str_ends_with($text, '?') && ! preg_match(self::QUESTION_START, $text)) {
                continue;
            }
            $questions++;
            $next = $heading->nextSibling;
            while ($next !== null && $next->nodeType !== XML_ELEMENT_NODE) {
                $next = $next->nextSibling;
            }
            $words = $next !== null && strtolower($next->nodeName) === 'p' ? preg_match_all('/[\p{L}\p{N}]+/u', $next->textContent) : 0;
            if ($words >= 20 && $words <= 60) {
                $answered++;
            }
        }

        $lists = 0;
        foreach ($xpath->query('//ul|//ol') as $list) {
            $lists += $xpath->query('./li', $list)->length >= 3 ? 1 : 0;
        }

        $host = parse_url($pageUrl, PHP_URL_HOST);
        $sources = [];
        foreach ($xpath->query('//p//a[@href]|//li//a[@href]|//blockquote//a[@href]') as $a) {
            $url = UrlNormalizer::resolve($a->getAttribute('href'), $pageUrl);
            $linkHost = $url !== null ? parse_url($url, PHP_URL_HOST) : null;
            if ($linkHost !== null && preg_replace('/^www\./', '', $linkHost) !== preg_replace('/^www\./', '', (string) $host)) {
                $sources[$linkHost] = true;
            }
        }

        $numbers = 0;
        foreach ($xpath->query('//p|//li') as $node) {
            $numbers += preg_match_all('/(%\s?\d|\d[\d.,]*\s?(%|yüzde|percent|milyon|milyar|bin\b|million|billion))/iu', $node->textContent);
        }

        // A quotation of at least five words; one-word <q> marks are usually typography, not a quoted source.
        $quotes = 0;
        foreach ($xpath->query('//blockquote|//q') as $node) {
            $quotes += preg_match_all('/[\p{L}\p{N}]+/u', $node->textContent) >= 5 ? 1 : 0;
        }

        $faq = stripos($html, '"FAQPage"') !== false || $xpath->query('//h2|//h3')->length > 0 && collect(iterator_to_array($xpath->query('//h2|//h3')))
            ->contains(fn ($node) => preg_match('/sıkça sorulan|sık sorulan|\bsss\b|\bfaq\b|frequently asked/iu', $node->textContent));

        return ['questions' => $questions, 'answered' => $answered, 'lists' => $lists, 'tables' => $xpath->query('//table')->length,
            'faq' => $faq, 'sources' => count($sources), 'numbers' => $numbers, 'quotes' => $quotes];
    }

    /**
     * Name and phone of the site's own organisation in the structured data (Organization and its subtypes such as
     * LocalBusiness), compared across pages for consistent NAP (name, address, phone).
     *
     * @param  array<int, array<mixed>>  $blocks
     * @return array{name: ?string, phone: ?string, logo: bool, address: ?string}|null
     */
    private static function entity(array $blocks): ?array
    {
        $found = null;
        $walk = function ($node) use (&$walk, &$found): void {
            if ($found !== null || ! is_array($node)) {
                return;
            }
            $types = array_map(fn ($type) => is_string($type) ? preg_replace('#^https?://schema\.org/#', '', $type) : '', (array) ($node['@type'] ?? []));
            if (! array_is_list($node) && collect($types)->contains(fn (string $type) => in_array(SchemaRules::ruleType($type), ['Organization', 'LocalBusiness'], true)) && (is_string($node['name'] ?? null) || isset($node['telephone']))) {
                $found = [
                    'name' => is_string($node['name'] ?? null) ? mb_substr(trim($node['name']), 0, 120) : null,
                    'phone' => self::phone($node['telephone'] ?? null),
                    'logo' => ! empty($node['logo']) || ! empty($node['image']),
                    'address' => self::address($node['address'] ?? null),
                ];

                return;
            }
            foreach ($node as $key => $value) {
                if (is_array($value) && $key !== '@context') {
                    $walk($value);
                }
            }
        };
        array_walk($blocks, fn ($block) => $walk($block));

        return $found;
    }

    /** Street and postal code of a schema address, lower-cased with single spaces, for comparing pages; null when absent. */
    public static function address(mixed $value): ?string
    {
        if (is_array($value) && array_is_list($value)) {
            $value = $value[0] ?? null;
        }
        $text = is_string($value) ? $value : (is_array($value) ? implode(' ', array_filter([$value['streetAddress'] ?? null, $value['postalCode'] ?? null], 'is_string')) : '');
        $text = trim(preg_replace('/[\s,.]+/u', ' ', mb_strtolower($text)));

        return $text === '' ? null : mb_substr($text, 0, 120);
    }

    /** The last 10 digits of a phone number, so "+90 212 555 00 00" and "0212 555 00 00" match; null when too short. */
    public static function phone(mixed $value): ?string
    {
        $digits = is_string($value) ? preg_replace('/\D/', '', $value) : '';

        return strlen($digits) >= 7 ? substr($digits, -10) : null;
    }

    private static function similar(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '' || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        similar_text($a, $b, $percent);

        return $percent >= 60;
    }

    /**
     * Every number written on the page, read both as 1.500,00 (Turkish) and 1,500.00 (English).
     *
     * @return array<int, float>
     */
    private static function visibleNumbers(string $text): array
    {
        preg_match_all('/\d[\d.,\s]*\d|\d/u', $text, $match);
        $numbers = [];
        foreach ($match[0] as $raw) {
            $raw = preg_replace('/\s+/u', '', $raw);
            foreach ([['.', ','], [',', '.']] as [$thousands, $decimal]) {
                $value = str_replace([$thousands, $decimal], ['', '.'], $raw);
                if (is_numeric($value)) {
                    $numbers[] = round((float) $value, 2);
                }
            }
        }

        return array_values(array_unique($numbers));
    }

    private static function wordPressContent(DOMXPath $xpath, string $bodyClass): array
    {
        $body = preg_split('/\s+/', trim($bodyClass), -1, PREG_SPLIT_NO_EMPTY);
        $has = fn (string $token) => in_array($token, $body, true);
        $prefixed = function (array $tokens, string $pattern): ?string {
            foreach ($tokens as $token) {
                if (preg_match($pattern, $token, $m) && ! ctype_digit($m[1])) {
                    return $m[1];
                }
            }

            return null;
        };

        $type = match (true) {
            $has('home') => 'home',
            $has('error404') => '404',
            $has('search') => 'search',
            $has('attachment') => 'attachment',
            $has('single') => $prefixed($body, '/^single-(?!format-)([a-z0-9_-]+)$/') ?? 'post',
            $has('blog') => 'blog',
            $has('page') && preg_grep('/^page-id-\d+$/', $body) !== [] => 'page',
            $has('category') => 'category',
            $has('tag') => 'tag',
            $has('author') => 'author',
            $has('date') => 'date',
            ($archive = $prefixed($body, '/^post-type-archive-([a-z0-9_-]+)$/')) !== null => 'archive:'.$archive,
            ($tax = $prefixed($body, '/^tax-([a-z0-9_-]+)$/')) !== null => 'tax:'.$tax,
            default => null,
        };

        $category = null;
        if ($type === 'category') {
            $category = $prefixed($body, '/^category-([a-z0-9%_-]+)$/');
        } elseif ($type !== null && ! in_array($type, ['home', 'blog', 'page', '404', 'search'], true)) {
            foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " status-publish ")]/@class') as $attribute) {
                $category = $prefixed(preg_split('/\s+/', strtolower($attribute->nodeValue), -1, PREG_SPLIT_NO_EMPTY), '/^(?:category|product_cat)-([a-z0-9%_-]+)$/');
                if ($category !== null) {
                    break;
                }
            }
        }

        return [$type, $category === null ? null : mb_substr(urldecode($category), 0, 190)];
    }

    private static function meta(DOMXPath $xpath, string $name): ?string
    {
        foreach ($xpath->query('//meta[@name]') as $node) {
            if (strtolower($node->getAttribute('name')) === $name) {
                return self::text($node->getAttribute('content'));
            }
        }

        return null;
    }

    /**
     * What a search engine reads as the link's text: the visible text, else image alt text, else aria-label or title.
     */
    private static function anchorText(DOMXPath $xpath, \DOMElement $a): string
    {
        $text = self::text($a->textContent) ?? '';
        if ($text !== '') {
            return $text;
        }
        foreach ($xpath->query('.//img/@alt', $a) as $alt) {
            if (($alt = self::text($alt->nodeValue)) !== '') {
                return $alt;
            }
        }

        return self::text($a->getAttribute('aria-label')) ?: (self::text($a->getAttribute('title')) ?? '');
    }

    private static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
