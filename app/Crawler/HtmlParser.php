<?php

namespace App\Crawler;

use DOMDocument;
use DOMXPath;

class HtmlParser
{
    /** Below this many words in the raw HTML, a JavaScript app shell has no readable content without rendering. */
    private const JS_SHELL_WORDS = 50;

    /**
     * @return array{title: ?string, description: ?string, meta_robots: ?string, canonical: ?string, h1: array<int, string>, word_count: int, content_hash: string, links: array<int, array{url: string, nofollow: bool}>, meta_refresh: ?string, generator: ?string, wordpress_hint: bool, images_missing_alt: int, images_missing_size: int, images: array<int, string>, mixed_content: int, page_type: ?string, seo_plugin: ?string, schema_types: array<int, string>, schema_errors: int, js_shell: bool, wp_type: ?string, wp_category: ?string}
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
        foreach ($xpath->query('//link[@href]') as $link) {
            if (in_array('canonical', preg_split('/\s+/', strtolower($link->getAttribute('rel'))), true)) {
                $canonical = UrlNormalizer::resolve($link->getAttribute('href'), $pageUrl);
                break;
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
        foreach ($xpath->query('//a[@href]') as $a) {
            $url = UrlNormalizer::resolve($a->getAttribute('href'), $pageUrl);
            if ($url !== null) {
                $links[] = ['url' => $url, 'nofollow' => str_contains(strtolower($a->getAttribute('rel')), 'nofollow')];
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

        [$schemaTypes, $schemaErrors] = self::jsonLd($xpath);
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

        $wordpress = (bool) preg_match('#/wp-content/|/wp-json/|/wp-includes/#', $html)
            || ($generator !== null && stripos($generator, 'wordpress') !== false);

        return [
            'title' => $title,
            'description' => $description,
            'meta_robots' => $robots,
            'canonical' => $canonical,
            'h1' => $h1,
            'word_count' => $wordCount,
            'content_hash' => md5(trim($bodyText)),
            'links' => $links,
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
            'js_shell' => $wordCount < self::JS_SHELL_WORDS && ($spaRoot || $noscriptJs),
            'wordpress_hint' => $wordpress,
            'wp_type' => $wordpress ? $wpType : null,
            'wp_category' => $wordpress ? $wpCategory : null,
        ];
    }

    /**
     * Schema.org types found in JSON-LD blocks (including @graph and nested items) and the number of blocks that are not valid JSON.
     *
     * @return array{0: array<int, string>, 1: int}
     */
    private static function jsonLd(DOMXPath $xpath): array
    {
        $types = [];
        $errors = 0;

        foreach ($xpath->query('//script[@type]') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }

            $data = json_decode(trim($script->textContent), true);
            if (! is_array($data)) {
                $errors++;

                continue;
            }

            array_walk_recursive($data, function ($value, $key) use (&$types) {
                if ($key === '@type' && is_string($value)) {
                    $types[] = preg_replace('#^https?://schema\.org/#', '', $value);
                }
            });
        }

        return [array_values(array_unique($types)), $errors];
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

    private static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
