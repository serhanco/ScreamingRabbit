<?php

namespace App\Monitoring;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Checks the due monitors once (docs/08 §2). Runs inside the scheduler before the queue worker, so it never waits
 * behind a crawl batch. A failure is retried once after a short pause, and if two well-known canary sites are also
 * unreachable the problem is our own connection, so no incident is opened.
 */
class MonitorRunner
{
    public const TIMEOUT_SECONDS = 10;

    private const POOL_SIZE = 10;

    private const RETRY_DELAY_SECONDS = 5;

    private const SLOW_MS = 5000;

    private const SLOW_STREAK = 3;

    private const SSL_WARN_DAYS = 14;

    private const REMIND_MINUTES = 60;

    private const CANARIES = ['https://www.google.com/generate_204', 'https://1.1.1.1/'];

    /**
     * @return array{checked: int, down: int, suppressed: bool}
     */
    public static function run(int $retryDelaySeconds = self::RETRY_DELAY_SECONDS): array
    {
        $due = Monitor::query()->with('project')->where('is_active', true)->get()
            ->filter(fn (Monitor $m) => $m->last_checked_at === null || $m->last_checked_at->lte(now()->subMinutes($m->interval_minutes)->addSeconds(10)));

        if ($due->isEmpty()) {
            return ['checked' => 0, 'down' => 0, 'suppressed' => false];
        }

        $results = self::checkAll($due);

        $failed = $due->filter(fn (Monitor $m) => ! $results[$m->id]['ok']);
        if ($failed->isNotEmpty()) {
            sleep($retryDelaySeconds);
            foreach (self::checkAll($failed) as $id => $result) {
                $results[$id] = $result;
            }
        }

        $stillFailed = $due->filter(fn (Monitor $m) => ! $results[$m->id]['ok']);
        $suppressed = $stillFailed->isNotEmpty() && ! self::canariesReachable();

        // Delivered together at the end, so several sites going down at once reach each person as one message.
        Alerts::collect(function () use ($due, $results, $suppressed) {
            foreach ($due as $monitor) {
                self::record($monitor, $results[$monitor->id], $suppressed);
            }
        });

        return ['checked' => $due->count(), 'down' => $stillFailed->count(), 'suppressed' => $suppressed];
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return array<int, array{ok: bool, status: ?int, ms: ?int, error: ?string, detail: ?string}>
     */
    private static function checkAll(Collection $monitors): array
    {
        $results = [];

        foreach ($monitors->chunk(self::POOL_SIZE) as $chunk) {
            $started = microtime(true);
            $responses = Http::pool(fn (Pool $pool) => $chunk->map(fn (Monitor $m) => $pool->as((string) $m->id)
                ->withUserAgent(config('screamingrabbit.crawl_defaults.user_agent'))
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->get($m->url))->values()->all());
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            foreach ($chunk as $monitor) {
                $results[$monitor->id] = self::evaluate($monitor, $responses[(string) $monitor->id] ?? null, $elapsed);
            }
        }

        return $results;
    }

    /**
     * @return array{ok: bool, status: ?int, ms: ?int, error: ?string, detail: ?string}
     */
    private static function evaluate(Monitor $monitor, mixed $response, int $elapsed): array
    {
        if (! $response instanceof Response) {
            $message = $response instanceof \Throwable ? $response->getMessage() : 'Yanıt alınamadı';

            return ['ok' => false, 'status' => null, 'ms' => null, 'error' => self::classify($message), 'detail' => mb_substr($message, 0, 200)];
        }

        $ms = (int) round(($response->transferStats?->getTransferTime() ?? $elapsed / 1000) * 1000);
        $status = $response->status();

        if ($status >= 400) {
            return ['ok' => false, 'status' => $status, 'ms' => $ms, 'error' => 'http', 'detail' => 'HTTP '.$status];
        }
        if ($monitor->keyword && mb_stripos($response->body(), $monitor->keyword) === false) {
            return ['ok' => false, 'status' => $status, 'ms' => $ms, 'error' => 'keyword', 'detail' => '"'.$monitor->keyword.'" bulunamadı'];
        }

        return ['ok' => true, 'status' => $status, 'ms' => $ms, 'error' => null, 'detail' => null];
    }

    private static function classify(string $message): string
    {
        return match (true) {
            (bool) preg_match('/resolve host|getaddrinfo|Name or service not known/i', $message) => 'dns',
            (bool) preg_match('/timed out|timeout/i', $message) => 'timeout',
            (bool) preg_match('/SSL|certificate/i', $message) => 'ssl',
            default => 'connect',
        };
    }

    private static function canariesReachable(): bool
    {
        $responses = Http::pool(fn (Pool $pool) => array_map(fn ($url) => $pool->timeout(5)->get($url), self::CANARIES));

        return collect($responses)->contains(fn ($r) => $r instanceof Response && $r->status() < 500);
    }

    /**
     * @param  array{ok: bool, status: ?int, ms: ?int, error: ?string, detail: ?string}  $result
     */
    private static function record(Monitor $monitor, array $result, bool $suppressed): void
    {
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'checked_at' => now(),
            'ok' => $result['ok'],
            'status_code' => $result['status'],
            'response_ms' => $result['ms'],
            'error_type' => $result['error'],
        ]);

        $site = $monitor->project->name.' ('.$monitor->url.')';
        $open = $monitor->openIncident();

        if ($result['ok']) {
            $slowStreak = $result['ms'] > self::SLOW_MS ? $monitor->slow_streak + 1 : 0;
            if ($slowStreak === self::SLOW_STREAK) {
                Alerts::send('slow', "Yavaş: {$monitor->project->name}", "{$site} art arda ".self::SLOW_STREAK.' kontrolde '.round($result['ms'] / 1000, 1).' sn sürdü.', $monitor->project_id);
            }

            if ($open) {
                $open->update(['ended_at' => now(), 'notified_up_at' => now()]);
                Alerts::send('up', "Düzeldi: {$monitor->project->name}", "{$site} yeniden erişilebilir. Kesinti süresi: {$open->durationForHumans()}.", $monitor->project_id);
            }

            $monitor->update(['status' => 'up', 'last_checked_at' => now(), 'last_response_ms' => $result['ms'], 'slow_streak' => $slowStreak]);
            self::checkSsl($monitor);

            return;
        }

        $monitor->update(['last_checked_at' => now(), 'last_response_ms' => null]);
        if ($suppressed) {
            return;
        }

        if ($open && ($open->reminded_at ?? $open->started_at)->lte(now()->subMinutes(self::REMIND_MINUTES))) {
            $open->update(['reminded_at' => now()]);
            Alerts::send('down', "Hâlâ erişilemiyor: {$monitor->project->name}", "{$site} {$open->durationForHumans()} süredir yanıt vermiyor.", $monitor->project_id);
        }

        if ($open === null) {
            Incident::create(['monitor_id' => $monitor->id, 'started_at' => now(), 'cause' => $result['error'], 'detail' => $result['detail'], 'notified_down_at' => now()]);
            Alerts::send('down', "Erişilemiyor: {$monitor->project->name}", "{$site} yanıt vermiyor. Neden: ".(Monitor::CAUSES[$result['error']] ?? $result['error']).($result['detail'] ? " ({$result['detail']})" : '').'.', $monitor->project_id);
        }

        $monitor->update(['status' => 'down']);
    }

    /** Once a day: reads the certificate's expiry date and warns when it is close. */
    private static function checkSsl(Monitor $monitor): void
    {
        if (! str_starts_with($monitor->url, 'https://') || ($monitor->ssl_checked_at && $monitor->ssl_checked_at->gt(now()->subDay()))) {
            return;
        }

        $expires = SslCertificate::expiresAt((string) parse_url($monitor->url, PHP_URL_HOST));
        $monitor->update(['ssl_expires_at' => $expires, 'ssl_checked_at' => now()]);

        if ($expires && $expires->lt(now()->addDays(self::SSL_WARN_DAYS))) {
            Alerts::send('ssl', "SSL bitiyor: {$monitor->project->name}", "{$monitor->url} sertifikası {$expires->format('d.m.Y')} tarihinde bitiyor ({$expires->diffForHumans()}).", $monitor->project_id);
        }
    }

    /**
     * Rolls raw checks up into one row per monitor and day, kept permanently for the uptime bars and reports.
     */
    public static function rollup(\DateTimeInterface $day): void
    {
        $from = CarbonImmutable::parse($day)->startOfDay();

        MonitorCheck::query()
            ->whereBetween('checked_at', [$from, $from->endOfDay()])
            ->selectRaw('monitor_id, COUNT(*) as checks, SUM(CASE WHEN ok THEN 1 ELSE 0 END) as up_checks, AVG(response_ms) as avg_ms')
            ->groupBy('monitor_id')
            ->get()
            ->each(fn ($row) => DB::table('monitor_daily')->updateOrInsert(
                ['monitor_id' => $row->monitor_id, 'day' => $from->toDateString()],
                ['checks' => $row->checks, 'up_checks' => $row->up_checks, 'avg_ms' => $row->avg_ms === null ? null : (int) round($row->avg_ms)],
            ));
    }

    /**
     * Uptime per day for the last given days, oldest first; today comes from the raw checks. null means no data.
     *
     * @return array<string, ?float> Y-m-d => percentage
     */
    public static function dailyUptime(Monitor $monitor, int $days): array
    {
        $start = now()->startOfDay()->subDays($days - 1);
        $rows = DB::table('monitor_daily')->where('monitor_id', $monitor->id)
            ->where('day', '>=', $start->toDateString())->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $row = $rows[$date] ?? null;
            $result[$date] = $row && $row->checks > 0 ? round($row->up_checks / $row->checks * 100, 2) : null;
        }

        $today = $monitor->checks()->where('checked_at', '>=', now()->startOfDay())
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN ok THEN 1 ELSE 0 END) as up')->first();
        if ($today && $today->total > 0) {
            $result[now()->toDateString()] = round($today->up / $today->total * 100, 2);
        }

        return $result;
    }

    /** Raw checks are kept 30 days (docs/08 §2.4). */
    public static function prune(): int
    {
        return MonitorCheck::where('checked_at', '<', now()->subDays(30))->delete();
    }
}
