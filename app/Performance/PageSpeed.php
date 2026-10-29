<?php

namespace App\Performance;

use App\Audit\Auditor;
use App\Models\Crawl;
use App\Models\Setting;
use App\Tasks\TaskBuilder;
use Illuminate\Support\Facades\Http;

/**
 * Mobile PageSpeed Insights for a few sample pages of each finished crawl: the home page and the strongest page of
 * each WordPress content type, then the pages with the highest Link Score. Real-user Core Web Vitals (CrUX) come
 * with the same call. The API works without a key at low volume; a key from Google Cloud raises the quota.
 */
class PageSpeed
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public const SAMPLE_SIZE = 5;

    /** Google's "good" and "poor" limits at the 75th percentile (web.dev/vitals). */
    public const LIMITS = [
        'lcp' => ['good' => 2500, 'poor' => 4000],
        'inp' => ['good' => 200, 'poor' => 500],
        'cls' => ['good' => 0.1, 'poor' => 0.25],
    ];

    /** Lighthouse colours scores 0-49 red. */
    public const POOR_SCORE = 50;

    private const TIMEOUT_SECONDS = 60;

    private const CRAWLS_PER_RUN = 3;

    /** Crawls finished more than this long ago are not measured any more. */
    private const MAX_AGE_DAYS = 3;

    public static function prepare(Crawl $crawl): void
    {
        $pages = $crawl->pages()->where('status_code', 200)->where('content_type', 'like', '%html%');
        $home = (clone $pages)->orderBy('depth')->orderBy('id')->value('url');
        if ($home === null) {
            return;
        }

        $urls = [$home];
        foreach ((clone $pages)->whereNotNull('wp_type')->orderByDesc('link_score')->get(['url', 'wp_type'])->unique('wp_type') as $page) {
            $urls[] = $page->url;
        }
        $urls = array_merge($urls, (clone $pages)->orderByDesc('link_score')->limit(self::SAMPLE_SIZE * 2)->pluck('url')->all());
        $urls = array_slice(array_values(array_unique($urls)), 0, self::SAMPLE_SIZE);

        $crawl->update(['pagespeed' => array_map(fn (string $url) => ['url' => $url, 'state' => 'pending'], $urls)]);
    }

    /**
     * Measures one pending page of each recent crawl; called from the scheduler so each run stays short.
     */
    public static function runDue(): int
    {
        $done = 0;
        $crawls = Crawl::query()->where('status', 'completed')->where('pagespeed', 'like', '%"pending"%')
            ->where('finished_at', '>=', now()->subDays(self::MAX_AGE_DAYS))->orderBy('finished_at')->limit(self::CRAWLS_PER_RUN)->get();
        foreach ($crawls as $crawl) {
            if (self::measureNext($crawl)) {
                $done++;
            }
        }

        return $done;
    }

    public static function measureNext(Crawl $crawl): bool
    {
        $pages = $crawl->pagespeed;
        $index = collect($pages)->search(fn (array $page) => $page['state'] === 'pending');
        if ($index === false) {
            return false;
        }

        $pages[$index] = array_merge($pages[$index], self::measure($pages[$index]['url']));
        $crawl->update(['pagespeed' => $pages]);

        if (! collect($pages)->contains(fn (array $page) => $page['state'] === 'pending')) {
            // All samples are in: findings and the task list pick up the results.
            (new Auditor)->run($crawl->fresh());
            TaskBuilder::sync($crawl->project);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function measure(string $url): array
    {
        $query = ['url' => $url, 'strategy' => 'mobile', 'category' => 'performance'];
        if ($key = Setting::get('pagespeed_api_key')) {
            $query['key'] = $key;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get(self::ENDPOINT, $query);
        } catch (\Throwable $e) {
            return ['state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
        if (! $response->successful()) {
            $error = $response->status() === 429 ? 'Google günlük ücretsiz kotası doldu; Google ayarlarına PageSpeed API anahtarı ekleyin.' : mb_substr((string) $response->json('error.message', 'HTTP '.$response->status()), 0, 200);

            return ['state' => 'failed', 'error' => $error];
        }

        $audit = fn (string $id) => $response->json("lighthouseResult.audits.{$id}.numericValue");
        $field = fn (string $metric) => $response->json("loadingExperience.metrics.{$metric}.percentile");
        $cls = $field('CUMULATIVE_LAYOUT_SHIFT_SCORE');

        return [
            'state' => 'done',
            'score' => ($score = $response->json('lighthouseResult.categories.performance.score')) === null ? null : (int) round($score * 100),
            'lab' => [
                'lcp' => self::round($audit('largest-contentful-paint')),
                'tbt' => self::round($audit('total-blocking-time')),
                'cls' => ($value = $audit('cumulative-layout-shift')) === null ? null : round($value, 3),
            ],
            // Real Chrome users over the last 28 days; missing when the page has too little traffic.
            'field' => $field('LARGEST_CONTENTFUL_PAINT_MS') === null ? null : [
                'lcp' => $field('LARGEST_CONTENTFUL_PAINT_MS'),
                'inp' => $field('INTERACTION_TO_NEXT_PAINT'),
                'cls' => $cls === null ? null : round($cls / 100, 2),
            ],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array{lcp: ?int, inp: ?int, cls: ?float}  $field
     * @return array<string, string> metric => 'good' | 'needs' | 'poor'
     */
    public static function ratings(array $field): array
    {
        $ratings = [];
        foreach (self::LIMITS as $metric => $limit) {
            $value = $field[$metric] ?? null;
            if ($value !== null) {
                $ratings[$metric] = $value <= $limit['good'] ? 'good' : ($value <= $limit['poor'] ? 'needs' : 'poor');
            }
        }

        return $ratings;
    }

    public static function label(string $metric, int|float $value): string
    {
        return match ($metric) {
            'lcp' => 'LCP '.number_format($value / 1000, 1, ',', '.').' sn',
            'inp' => 'INP '.$value.' ms',
            'tbt' => 'TBT '.$value.' ms',
            default => 'CLS '.number_format($value, 2, ',', '.'),
        };
    }

    private static function round(mixed $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }
}
