<?php

namespace App\Crawler;

class UrlNormalizer
{
    private const ASSET_EXTENSIONS = 'pdf|jpe?g|png|gif|webp|avif|svg|ico|bmp|css|js|json|xml|txt|zip|gz|rar|7z|mp3|mp4|avi|mov|woff2?|ttf|eot|doc|docx|xls|xlsx|ppt|pptx|csv';

    /**
     * Resolves an href against the page it was found on. Returns null for anything that is not a plain http(s) URL.
     */
    public static function resolve(string $href, string $base): ?string
    {
        $href = trim(html_entity_decode($href));

        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript|data|sms|whatsapp):/i', $href)) {
            return null;
        }

        $b = parse_url($base);
        if (! $b || ! isset($b['scheme'], $b['host'])) {
            return null;
        }

        $href = preg_replace('/#.*$/', '', $href);

        if (str_starts_with($href, '//')) {
            $href = $b['scheme'].':'.$href;
        }

        $p = parse_url($href);
        if ($p === false) {
            return null;
        }

        if (isset($p['scheme'])) {
            if (! in_array(strtolower($p['scheme']), ['http', 'https'], true) || ! isset($p['host'])) {
                return null;
            }
            $scheme = strtolower($p['scheme']);
            $host = strtolower($p['host']);
            $port = $p['port'] ?? null;
            $path = $p['path'] ?? '/';
            $query = $p['query'] ?? null;
        } else {
            $scheme = $b['scheme'];
            $host = strtolower($b['host']);
            $port = $b['port'] ?? null;
            $query = $p['query'] ?? null;
            $path = $p['path'] ?? '';

            if ($path === '') {
                $path = $b['path'] ?? '/';
                $query = $p['query'] ?? ($href === '' ? ($b['query'] ?? null) : null);
            } elseif (! str_starts_with($path, '/')) {
                $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
                $path = $dir.$path;
            }
        }

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = self::removeDotSegments($path === '' ? '/' : $path);
        $path = preg_replace('#//+#', '/', $path);

        return $scheme.'://'.$host.($port ? ':'.$port : '').$path.($query !== null && $query !== '' ? '?'.$query : '');
    }

    public static function isInternal(string $url, string $baseUrl): bool
    {
        return self::bareHost($url) === self::bareHost($baseUrl);
    }

    public static function isAsset(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return (bool) preg_match('/\.('.self::ASSET_EXTENSIONS.')$/i', $path);
    }

    public static function hash(string $url): string
    {
        return sha1($url);
    }

    private static function bareHost(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function removeDotSegments(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($out);
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }
        $result = implode('/', $out);

        return str_starts_with($result, '/') ? $result : '/'.$result;
    }
}
