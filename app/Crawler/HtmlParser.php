<?php

namespace App\Crawler;

use DOMDocument;
use DOMXPath;

class HtmlParser
{
    /**
     * @return array{title: ?string, description: ?string, meta_robots: ?string, canonical: ?string, h1: array<int, string>, word_count: int, content_hash: string, links: array<int, array{url: string, nofollow: bool}>, meta_refresh: ?string, generator: ?string, wordpress_hint: bool, images_missing_alt: int, images: array<int, string>, mixed_content: int, page_type: ?string, seo_plugin: ?string}
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

        $seoPlugins = [];
        foreach (['yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math', 'aioseo' => 'All in One SEO'] as $key => $needle) {
            if (stripos($html, $needle) !== false) {
                $seoPlugins[] = $key;
            }
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
            'images' => $images,
            'mixed_content' => $mixedContent,
            'page_type' => $pageType,
            'seo_plugin' => $seoPlugins === [] ? null : implode(',', $seoPlugins),
            'wordpress_hint' => (bool) preg_match('#/wp-content/|/wp-json/|/wp-includes/#', $html)
                || ($generator !== null && stripos($generator, 'wordpress') !== false),
        ];
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
