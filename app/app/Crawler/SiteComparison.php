<?php

namespace App\Crawler;

use App\Models\Crawl;

/**
 * Compares two crawls of different sites by URL path, typically a staging copy against the live site before a
 * launch or migration: pages missing on one side, and pages whose status, title, description, H1, canonical or
 * indexability differ. Hosts are ignored so https://test.site.com/a and https://site.com/a are the same page.
 */
class SiteComparison
{
    public const SAMPLE = 50;

    /** Field => label; canonical is compared by path. */
    public const FIELDS = [
        'status_code' => 'Durum kodu', 'indexable' => 'İndekslenebilir', 'title' => 'Title', 'meta_description' => 'Meta description',
        'h1' => 'H1', 'canonical' => 'Canonical (yol)', 'meta_robots' => 'Meta robots',
    ];

    public function __construct(private Crawl $live, private Crawl $staging) {}

    /**
     * @return array{common: int, only_live: array{count: int, sample: array<int, string>}, only_staging: array{count: int, sample: array<int, string>}, fields: array<string, array{count: int, sample: array<int, array{path: string, live: string, staging: string}>}>}
     */
    public function data(): array
    {
        $live = $this->pages($this->live);
        $staging = $this->pages($this->staging);

        $onlyLive = array_keys(array_diff_key($live, $staging));
        $onlyStaging = array_keys(array_diff_key($staging, $live));
        $fields = array_fill_keys(array_keys(self::FIELDS), ['count' => 0, 'sample' => []]);

        foreach (array_intersect_key($live, $staging) as $path => $a) {
            $b = $staging[$path];
            foreach (array_keys(self::FIELDS) as $field) {
                if ($a[$field] !== $b[$field]) {
                    $fields[$field]['count']++;
                    if (count($fields[$field]['sample']) < self::SAMPLE) {
                        $fields[$field]['sample'][] = ['path' => $path, 'live' => $this->show($a[$field]), 'staging' => $this->show($b[$field])];
                    }
                }
            }
        }

        return [
            'common' => count(array_intersect_key($live, $staging)),
            'only_live' => ['count' => count($onlyLive), 'sample' => array_slice($onlyLive, 0, self::SAMPLE)],
            'only_staging' => ['count' => count($onlyStaging), 'sample' => array_slice($onlyStaging, 0, self::SAMPLE)],
            'fields' => array_filter($fields, fn (array $field) => $field['count'] > 0),
        ];
    }

    /** "/a/b?x=1" for https://host/a/b?x=1. */
    public static function path(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');

        return rtrim($path, '/') === '' ? '/' : $path;
    }

    /** @return array<string, array<string, mixed>> path => compared fields of the crawl's HTML and redirect pages */
    private function pages(Crawl $crawl): array
    {
        $pages = [];
        $query = $crawl->pages()->where('state', 'done')
            ->select(['id', 'url', 'state', 'status_code', 'content_type', 'title', 'meta_description', 'h1', 'canonical', 'meta_robots', 'x_robots']);
        foreach ($query->lazyById(1000) as $page) {
            $pages[self::path($page->url)] = [
                'status_code' => $page->status_code,
                'indexable' => $page->isIndexable(),
                'title' => trim((string) $page->title),
                'meta_description' => trim((string) $page->meta_description),
                'h1' => trim((string) $page->h1),
                'canonical' => self::path($page->canonical),
                'meta_robots' => strtolower(trim((string) $page->meta_robots)),
            ];
        }

        return $pages;
    }

    private function show(mixed $value): string
    {
        return match (true) {
            $value === true => 'Evet',
            $value === false => 'Hayır',
            $value === null || $value === '' => '—',
            default => mb_strimwidth((string) $value, 0, 120, '…'),
        };
    }
}
