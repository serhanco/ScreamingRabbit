<?php

namespace App\Performance;

use App\Models\Crawl;
use App\Models\Setting;
use App\Models\SpeedResult;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Site-wide speed scan: after a crawl that asked for it, every HTML page that answered 200 is measured with PageSpeed
 * Insights on mobile and/or desktop. A few calls run side by side each scheduler minute, so the shared host never
 * holds a long request and Google's per-minute quota is respected. A quota answer (429) pauses the whole scan for a
 * while instead of burning the remaining pages.
 */
class SpeedScan
{
    public const BACKOFF_KEY = 'speed-scan:backoff';

    /** Lighthouse lab limits for the same assessment when a page has no real-user data; TBT stands in for INP. */
    public const LAB_LIMITS = [
        'lcp' => ['good' => 2500, 'poor' => 4000],
        'tbt' => ['good' => 200, 'poor' => 600],
        'cls' => ['good' => 0.1, 'poor' => 0.25],
    ];

    private const MAX_ATTEMPTS = 2;

    /** A PSI call takes 10-30 seconds; a new round only starts while a whole one still fits the minute. */
    private const ROUND_BUDGET_SECONDS = 20;

    /** Rows left "running" by a killed process go back in the queue after this long. */
    private const STALE_MINUTES = 5;

    /**
     * @param  array<int, string>  $strategies
     */
    public static function prepare(Crawl $crawl): int
    {
        $strategies = array_values(array_intersect(array_keys(SpeedResult::STRATEGIES), $crawl->settings['speed_strategies'] ?? ['mobile', 'desktop'])) ?: ['mobile'];
        $count = 0;

        // Every URL is stored once per strategy (the crawl already deduplicated them by normalized URL).
        $crawl->pages()->where('status_code', 200)->where('content_type', 'like', '%html%')
            ->select('id', 'url', 'url_hash', 'wp_type', 'depth')->orderBy('id')
            ->chunkById(500, function ($pages) use ($crawl, $strategies, &$count) {
                $rows = [];
                foreach ($pages as $page) {
                    foreach ($strategies as $strategy) {
                        $rows[] = ['crawl_id' => $crawl->id, 'url' => $page->url, 'url_hash' => $page->url_hash, 'page_type' => $page->depth === 0 ? 'home' : $page->wp_type,
                            'strategy' => $strategy, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                $count += DB::table('speed_results')->insertOrIgnore($rows);
            });

        return $count;
    }

    /**
     * Called every minute from the scheduler.
     */
    public static function runDue(): int
    {
        if (Cache::has(self::BACKOFF_KEY)) {
            return 0;
        }

        SpeedResult::query()->where('state', 'running')->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))->update(['state' => 'pending']);

        $started = microtime(true);
        $done = 0;
        do {
            $measured = self::round();
            $done += $measured;
        } while ($measured > 0 && ! Cache::has(self::BACKOFF_KEY) && microtime(true) - $started < self::ROUND_BUDGET_SECONDS);

        return $done;
    }

    /**
     * Measures the next few pending rows side by side; oldest scan first so scans finish one after another.
     */
    public static function round(): int
    {
        $rows = SpeedResult::query()->where('state', 'pending')
            ->whereIn('crawl_id', Crawl::query()->select('id')->where('speed_scan', true)->where('speed_paused', false)->where('status', 'completed'))
            ->orderBy('crawl_id')->orderBy('id')->limit(self::concurrency())->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        SpeedResult::query()->whereKey($rows->modelKeys())->update(['state' => 'running', 'updated_at' => now()]);
        // Keep the loaded models in step, so putting a row back to "pending" below is a real change.
        $rows->each(fn (SpeedResult $row) => $row->forceFill(['state' => 'running'])->syncOriginal());
        $key = Setting::get('pagespeed_api_key');
        $timeout = (int) config('screamingrabbit.speed_scan.timeout');

        $responses = Http::pool(fn (Pool $pool) => $rows->map(fn (SpeedResult $row) => $pool->as((string) $row->id)->timeout($timeout)
            ->get(PageSpeed::ENDPOINT, array_filter(['url' => $row->url, 'strategy' => $row->strategy, 'category' => 'performance', 'key' => $key])))->all());

        foreach ($rows as $row) {
            $response = $responses[(string) $row->id] ?? null;

            if ($response instanceof Response && $response->status() === 429) {
                // Quota: try again later, without counting it against the page.
                $row->update(['state' => 'pending']);
                Cache::put(self::BACKOFF_KEY, now()->addMinutes((int) config('screamingrabbit.speed_scan.backoff_minutes'))->toIso8601String(), now()->addMinutes((int) config('screamingrabbit.speed_scan.backoff_minutes')));

                continue;
            }

            if ($response instanceof Response && $response->successful()) {
                $row->update(self::parse($response) + ['state' => 'done', 'error' => null, 'attempts' => $row->attempts + 1, 'checked_at' => now()]);

                continue;
            }

            $error = $response instanceof Response
                ? (string) $response->json('error.message', 'HTTP '.$response->status())
                : ($response instanceof \Throwable ? $response->getMessage() : 'Yanıt alınamadı');
            $attempts = $row->attempts + 1;
            $row->update(['state' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending', 'attempts' => $attempts, 'error' => mb_substr($error, 0, 300), 'checked_at' => now()]);
        }

        return $rows->count();
    }

    /**
     * @return array<string, mixed>
     */
    public static function parse(Response $response): array
    {
        $audit = fn (string $id) => $response->json("lighthouseResult.audits.{$id}.numericValue");
        $ms = fn (mixed $value) => $value === null ? null : (int) round($value);
        $field = fn (string $metric) => $response->json("loadingExperience.metrics.{$metric}.percentile");
        $score = $response->json('lighthouseResult.categories.performance.score');
        $fieldCls = $field('CUMULATIVE_LAYOUT_SHIFT_SCORE');

        $values = [
            'score' => $score === null ? null : (int) round($score * 100),
            'fcp' => $ms($audit('first-contentful-paint')),
            'lcp' => $ms($audit('largest-contentful-paint')),
            'tbt' => $ms($audit('total-blocking-time')),
            'cls' => ($cls = $audit('cumulative-layout-shift')) === null ? null : round($cls, 3),
            'si' => $ms($audit('speed-index')),
            'ttfb' => $ms($audit('server-response-time')),
            'field_lcp' => $field('LARGEST_CONTENTFUL_PAINT_MS'),
            'field_inp' => $field('INTERACTION_TO_NEXT_PAINT'),
            'field_cls' => $fieldCls === null ? null : round($fieldCls / 100, 2),
            'field_fcp' => $field('FIRST_CONTENTFUL_PAINT_MS'),
            'field_ttfb' => $field('EXPERIMENTAL_TIME_TO_FIRST_BYTE'),
        ];

        return $values + self::assess($values);
    }

    /**
     * Core Web Vitals verdict as Google gives it: from real users when there is enough data (all three good = good,
     * any poor = poor), otherwise an estimate from the lab run.
     *
     * @param  array<string, mixed>  $values
     * @return array{cwv: ?string, cwv_from_field: bool}
     */
    public static function assess(array $values): array
    {
        if ($values['field_lcp'] !== null) {
            $ratings = PageSpeed::ratings(['lcp' => $values['field_lcp'], 'inp' => $values['field_inp'], 'cls' => $values['field_cls']]);

            return ['cwv' => self::worst($ratings), 'cwv_from_field' => true];
        }

        $ratings = [];
        foreach (self::LAB_LIMITS as $metric => $limit) {
            if ($values[$metric] !== null) {
                $ratings[] = $values[$metric] <= $limit['good'] ? 'good' : ($values[$metric] <= $limit['poor'] ? 'needs' : 'poor');
            }
        }

        return ['cwv' => self::worst($ratings), 'cwv_from_field' => false];
    }

    /**
     * @return array{total: int, done: int, failed: int, cancelled: int, pending: int, percent: int, minutes_left: int, paused_until: ?string,
     *     strategies: array<string, array{count: int, average: ?int, good: int, needs: int, poor: int, slow: int}>}
     */
    public static function summary(Crawl $crawl): array
    {
        $states = SpeedResult::query()->where('crawl_id', $crawl->id)->selectRaw('state, COUNT(*) as n')->groupBy('state')->pluck('n', 'state');
        $total = (int) $states->sum();
        $pending = (int) (($states['pending'] ?? 0) + ($states['running'] ?? 0));
        $finished = $total - $pending;

        $strategies = [];
        foreach (SpeedResult::STRATEGIES as $strategy => $label) {
            $done = SpeedResult::query()->where('crawl_id', $crawl->id)->where('strategy', $strategy)->where('state', 'done');
            $count = (clone $done)->count();
            if ($count === 0) {
                continue;
            }
            $cwv = (clone $done)->selectRaw('cwv, COUNT(*) as n')->groupBy('cwv')->pluck('n', 'cwv');
            $strategies[$strategy] = [
                'count' => $count,
                'average' => ($avg = (clone $done)->avg('score')) === null ? null : (int) round($avg),
                'good' => (int) ($cwv['good'] ?? 0),
                'needs' => (int) ($cwv['needs'] ?? 0),
                'poor' => (int) ($cwv['poor'] ?? 0),
                'slow' => (clone $done)->where('score', '<', PageSpeed::POOR_SCORE)->count(),
            ];
        }

        $perMinute = self::concurrency();

        return [
            'total' => $total,
            'done' => (int) ($states['done'] ?? 0),
            'failed' => (int) ($states['failed'] ?? 0),
            'cancelled' => (int) ($states['cancelled'] ?? 0),
            'pending' => $pending,
            'percent' => $total > 0 ? (int) floor($finished / $total * 100) : 0,
            'minutes_left' => (int) ceil($pending / $perMinute),
            'paused_until' => $pending > 0 ? Cache::get(self::BACKOFF_KEY) : null,
            'strategies' => $strategies,
        ];
    }

    /** Stops measuring until resume(); pages already being measured still finish. */
    public static function pause(Crawl $crawl): void
    {
        $crawl->update(['speed_paused' => true]);
    }

    public static function resume(Crawl $crawl): void
    {
        $crawl->update(['speed_paused' => false]);
    }

    /** Ends the scan: pages not measured yet are marked as stopped and keep no place in the queue. */
    public static function stop(Crawl $crawl): int
    {
        $crawl->update(['speed_paused' => false]);

        return $crawl->speedResults()->where('state', 'pending')->update(['state' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * Puts pages back in the queue: all of them (measure the whole site again, e.g. after a speed fix), or only the
     * ones that failed or were stopped. Old values stay visible until the new measurement replaces them.
     */
    public static function requeue(Crawl $crawl, bool $all = false): int
    {
        $crawl->update(['speed_paused' => false]);
        $query = $crawl->speedResults()->whereIn('state', $all ? ['done', 'failed', 'cancelled'] : ['failed', 'cancelled']);

        return $query->update(['state' => 'pending', 'attempts' => 0, 'error' => null, 'updated_at' => now()]);
    }

    public static function requeueOne(SpeedResult $result): void
    {
        if ($result->state !== 'running') {
            $result->update(['state' => 'pending', 'attempts' => 0, 'error' => null]);
        }
    }

    /** Pages measured side by side per round (about per minute): more with an API key, which has a much larger quota. */
    public static function concurrency(): int
    {
        return max(1, (int) config(filled(Setting::get('pagespeed_api_key')) ? 'screamingrabbit.speed_scan.concurrency' : 'screamingrabbit.speed_scan.concurrency_without_key'));
    }

    /**
     * @param  array<int|string, string>  $ratings
     */
    private static function worst(array $ratings): ?string
    {
        if ($ratings === []) {
            return null;
        }

        return in_array('poor', $ratings, true) ? 'poor' : (in_array('needs', $ratings, true) ? 'needs' : 'good');
    }
}
