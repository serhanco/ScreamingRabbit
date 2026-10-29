<?php

namespace App\Crawler;

use App\Audit\SchemaRules;
use App\Audit\SimHash;
use DOMDocument;
use DOMXPath;

class HtmlParser
{
    /** Below this many words in the raw HTML, a JavaScript app shell has no readable content without rendering. */
    private const JS_SHELL_WORDS = 50;

    /** Link texts that say nothing about the target page (Google: "write good anchor text", Lighthouse "link-text" audit). */
    public const GENERIC_ANCHORS = [
        'buraya tıklayın', 'buraya tıklayınız', 'buraya tıkla', 'tıklayın', 'tıklayınız', 'tıkla', 'buraya', 'burada', 'burası',
        'devamı', 'devamını oku', 'devamını okuyun', 'devamını okuyunuz', 'devamı için tıklayın', 'daha fazla', 'daha fazlası',
        'daha fazla bilgi', 'detay', 'detaylar', 'detaylı bilgi', 'ayrıntılar', 'incele', 'göz at', 'link', 'bağlantı', 'git',
        'click here', 'click', 'here', 'read more', 'more', 'learn more', 'more info', 'details', 'this', 'this page', 'go',
        'continue', 'right here',
    ];

    /**
     * @return array{title: ?string, description: ?string, meta_robots: ?string, canonical: ?string, hreflang: array<int, array{lang: string, url: string}>, h1: array<int, string>, word_count: int, content_hash: string, simhash: ?string, links: array<int, array{url: string, nofollow: bool}>, uncrawlable_links: int, empty_anchors: int, generic_anchors: array<int, string>, generic_anchor_count: int, meta_refresh: ?string, generator: ?string, wordpress_hint: bool, images_missing_alt: int, images_missing_size: int, images: array<int, string>, mixed_content: int, page_type: ?string, seo_plugin: ?string, schema_types: array<int, string>, schema_errors: int, schema_problems: array<int, array{type: string, required: array<int, string>, recommended: array<int, string>}>, schema_duplicates: array<int, string>, js_shell: bool, wp_type: ?string, wp_category: ?string}
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
        $hreflang = [];
        foreach ($xpath->query('//link[@href]') as $link) {
            $rel = preg_split('/\s+/', strtolower($link->getAttribute('rel')));
            if ($canonical === null && in_array('canonical', $rel, true)) {
                $canonical = UrlNormalizer::resolve($link->getAttribute('href'), $pageUrl);
            } elseif (in_array('alternate', $rel, true) && trim($link->getAttribute('hreflang')) !== '') {
                $url = UrlNormalizer::resolve($link->getAttribute('href'), $pageUrl);
                if ($url !== null && count($hreflang) < 100) {
                    $hreflang[] = ['lang' => trim($link->getAttribute('hreflang')), 'url' => $url];
                }
            }
        }

        $h1 = [];
        foreach ($xpath->query('//h1') as $node) {
            $h1[] = self::text($node->textContent) ?? '';
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

        $bodyClass = ' '.strtolower((string) $xpath->query('//body/@class')->item(0)?->nodeValue).' ';
        $pageType = match (true) {
            str_contains($bodyClass, ' attachment ') || str_contains($bodyClass, ' attachment-') => 'attachment',
            str_contains($bodyClass, ' archive ') => 'archive',
            default => null,
        };

        [$schemaTypes, $schemaErrors, $schemaProblems, $schemaDuplicates] = self::jsonLd($xpath);
        [$wpType, $wpCategory] = self::wordPressContent($xpath, $bodyClass);

        $seoPlugins = [];
        foreach (['yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math', 'aioseo' => 'All in One SEO'] as $key => $needle) {
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
        $wordCount = (int) preg_match_all('/[\p{L}\p{N}]+/u', $bodyText);

        // The near-duplicate fingerprint uses the main text only, so a shared menu and footer don't make pages look alike.
        foreach ($xpath->query('//nav|//header|//footer|//aside|//form') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $mainText = [];
        foreach ($xpath->query('//text()') as $node) {
            $mainText[] = $node->nodeValue;
        }

        $wordpress = (bool) preg_match('#/wp-content/|/wp-json/|/wp-includes/#', $html)
            || ($generator !== null && stripos($generator, 'wordpress') !== false);

        return [
            'title' => $title,
            'description' => $description,
            'meta_robots' => $robots,
            'canonical' => $canonical,
            'hreflang' => $hreflang,
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
            'page_type' => $pageType,
            'seo_plugin' => $seoPlugins === [] ? null : implode(',', $seoPlugins),
            'schema_types' => $schemaTypes,
            'schema_errors' => $schemaErrors,
            'schema_problems' => $schemaProblems,
            'schema_duplicates' => $schemaDuplicates,
            'js_shell' => $wordCount < self::JS_SHELL_WORDS && ($spaRoot || $noscriptJs),
            'wordpress_hint' => $wordpress,
            'wp_type' => $wordpress ? $wpType : null,
            'wp_category' => $wordpress ? $wpCategory : null,
        ];
    }

    /**
     * Structured data on the page: schema.org types from JSON-LD (including @graph and nested items) and Microdata,
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
