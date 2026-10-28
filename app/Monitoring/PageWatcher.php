<?php

namespace App\Monitoring;

use App\Crawler\HtmlParser;
use App\Models\WatchedPage;
use App\Models\WatchedPageChange;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Checks the due critical pages (docs/09 Faz 4) and alerts when the status code, redirect target, noindex, canonical
 * or title changes. A change is confirmed by a second fetch before it is recorded, so one bad response raises nothing.
 */
class PageWatcher
{
    private const PER_RUN = 50;

    private const POOL_SIZE = 5;

    public static function runDue(): int
    {
        $due = WatchedPage::query()->with('project')->where('is_active', true)->orderBy('last_checked_at')->get()
            ->filter(fn (WatchedPage $page) => $page->isDue())->take(self::PER_RUN)->values();
        if ($due->isEmpty()) {
            return 0;
        }

        $states = self::fetchAll($due);
        $changed = $due->filter(fn (WatchedPage $page) => $page->state !== null && self::diff($page->state, $states[$page->id]) !== []);
        if ($changed->isNotEmpty()) {
            foreach (self::fetchAll($changed) as $id => $state) {
                // The second reading wins: after a one-off blip it matches the old state again and nothing is recorded.
                $states[$id] = $state;
            }
        }

        foreach ($due as $page) {
            self::record($page, $states[$page->id]);
        }

        return $due->count();
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{0: mixed, 1: mixed}> field => [old, new]
     */
    public static function diff(array $old, array $new): array
    {
        $changes = [];
        foreach (array_keys(WatchedPage::FIELDS) as $field) {
            if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                $changes[$field] = [$old[$field] ?? null, $new[$field] ?? null];
            }
        }

        return $changes;
    }

    /**
     * The signals that matter to search engines, read without following redirects so a new 301 shows up as one.
     *
     * @return array{status: ?int, location: ?string, noindex: bool, canonical: ?string, title: ?string}
     */
    public static function read(mixed $response, string $url): array
    {
        if (! $response instanceof Response) {
            return ['status' => null, 'location' => null, 'noindex' => false, 'canonical' => null, 'title' => null];
        }

        $state = ['status' => $response->status(), 'location' => $response->header('Location') ?: null, 'noindex' => false, 'canonical' => null, 'title' => null];
        $noindexHeader = str_contains(strtolower($response->header('X-Robots-Tag')), 'noindex');
        if (str_contains(strtolower($response->header('Content-Type')), 'html') && $response->status() < 300) {
            $parsed = HtmlParser::parse($response->body(), $url);
            $state['title'] = $parsed['title'];
            $state['canonical'] = $parsed['canonical'];
            $state['noindex'] = $noindexHeader || str_contains(strtolower((string) $parsed['meta_robots']), 'noindex');
        } else {
            $state['noindex'] = $noindexHeader;
        }

        return $state;
    }

    /**
     * @param  Collection<int, WatchedPage>  $pages
     * @return array<int, array<string, mixed>>
     */
    private static function fetchAll(Collection $pages): array
    {
        $states = [];
        foreach ($pages->chunk(self::POOL_SIZE) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => $chunk->map(fn (WatchedPage $page) => $pool->as((string) $page->id)
                ->withUserAgent(config('screamingrabbit.crawl_defaults.user_agent'))
                ->withOptions(['allow_redirects' => false])
                ->timeout(MonitorRunner::TIMEOUT_SECONDS)
                ->get($page->url))->values()->all());

            foreach ($chunk as $page) {
                $states[$page->id] = self::read($responses[(string) $page->id] ?? null, $page->url);
            }
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function record(WatchedPage $page, array $state): void
    {
        $changes = $page->state === null ? [] : self::diff($page->state, $state);
        $page->fill(['state' => $state, 'last_checked_at' => now()]);
        if ($changes !== []) {
            $page->last_changed_at = now();
            foreach ($changes as $field => [$old, $new]) {
                WatchedPageChange::create([
                    'watched_page_id' => $page->id, 'detected_at' => now(), 'field' => $field,
                    'old_value' => self::show($field, $old), 'new_value' => self::show($field, $new),
                ]);
            }
            Alerts::send('page_change', '⚠️ Kritik sayfa değişti: '.$page->project->name, self::message($page, $changes), $page->project_id);
        }
        $page->save();
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private static function message(WatchedPage $page, array $changes): string
    {
        $lines = [$page->url, ''];
        foreach ($changes as $field => [$old, $new]) {
            $lines[] = WatchedPage::FIELDS[$field].': '.self::show($field, $old).' → '.self::show($field, $new);
        }

        return implode("\n", $lines);
    }

    public static function show(string $field, mixed $value): string
    {
        return match (true) {
            $field === 'noindex' => $value ? 'var' : 'yok',
            $field === 'status' && $value === null => 'yanıt yok',
            $value === null || $value === '' => '(boş)',
            default => (string) $value,
        };
    }
}
