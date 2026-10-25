<?php

namespace App\Crawler;

class SitemapReader
{
    /**
     * Collects page URLs from a sitemap or sitemap index.
     *
     * @param  callable(string): ?string  $fetch  returns the body of a URL or null
     * @return array<int, string>
     */
    public static function read(string $url, callable $fetch, int $limit, int $depth = 0): array
    {
        $body = $fetch($url);
        if ($body === null || trim($body) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, null, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        $urls = [];

        if ($xml->getName() === 'sitemapindex' && $depth < 2) {
            foreach (array_slice(iterator_to_array($xml->sitemap, false), 0, 30) as $child) {
                $urls = array_merge($urls, self::read(trim((string) $child->loc), $fetch, $limit - count($urls), $depth + 1));
                if (count($urls) >= $limit) {
                    break;
                }
            }
        } elseif ($xml->getName() === 'urlset') {
            foreach ($xml->url as $entry) {
                $urls[] = trim((string) $entry->loc);
                if (count($urls) >= $limit) {
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }
}
