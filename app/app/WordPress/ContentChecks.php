<?php

namespace App\WordPress;

use App\Crawler\UrlNormalizer;
use App\Models\CrawlPage;
use App\Models\WpEvent;
use App\Monitoring\Alerts;
use App\Monitoring\MonitorRunner;
use App\Monitoring\PageWatcher;
use Illuminate\Support\Facades\Http;

/**
 * Re-checks URLs the Connector plugin reported as published, updated or unpublished (docs/09 Faz 6, WP-3) and alerts
 * when new content cannot rank: an error, a redirect, noindex or a canonical pointing elsewhere; or when removed
 * content still has internal links pointing at it.
 */
class ContentChecks
{
    /** Wait this long after the change so page caches have caught up. */
    private const DELAY_SECONDS = 60;

    private const PER_RUN = 20;

    private const KEEP_DAYS = 90;

    public static function runDue(): int
    {
        WpEvent::where('received_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        $due = WpEvent::with('project')->whereNull('checked_at')->where('received_at', '<=', now()->subSeconds(self::DELAY_SECONDS))
            ->orderBy('received_at')->limit(self::PER_RUN)->get();
        foreach ($due as $event) {
            self::check($event);
        }

        return $due->count();
    }

    public static function check(WpEvent $event): void
    {
        try {
            $response = Http::withUserAgent(config('screamingrabbit.crawl_defaults.user_agent'))->withOptions(['allow_redirects' => false])
                ->timeout(MonitorRunner::TIMEOUT_SECONDS)->get($event->url);
        } catch (\Throwable) {
            $response = null;
        }
        $state = PageWatcher::read($response, $event->url);
        $problems = $event->event === 'unpublished' ? self::removedProblems($event, $state) : self::liveProblems($event->url, $state);

        $event->update(['checked_at' => now(), 'state' => $state, 'problems' => $problems]);

        if ($problems !== []) {
            Alerts::send('content_problem', '⚠️ İçerikte sorun: '.$event->project->name,
                implode("\n", [WpEvent::EVENTS[$event->event].': '.$event->url, '', ...array_map(fn (string $problem) => '• '.$problem, $problems)]), $event->project_id);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    public static function liveProblems(string $url, array $state): array
    {
        $status = $state['status'];

        return array_values(array_filter([
            $status === null ? 'Sayfa yanıt vermedi' : null,
            $status !== null && $status >= 400 ? "Sayfa HTTP {$status} veriyor" : null,
            $status !== null && $status >= 300 && $status < 400 ? 'Sayfa yönlendiriyor: '.($state['location'] ?? '?') : null,
            $state['noindex'] ? 'Sayfa noindex: Google dizine almaz' : null,
            filled($state['canonical']) && self::normalize($state['canonical']) !== self::normalize($url) ? 'Canonical başka adresi gösteriyor: '.$state['canonical'] : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    private static function removedProblems(WpEvent $event, array $state): array
    {
        if ($state['status'] === null || $state['status'] < 400) {
            return [];
        }
        $crawl = $event->project->crawls()->where('status', 'completed')->latest('finished_at')->first();
        $inlinks = $crawl === null ? 0 : (int) CrawlPage::where('crawl_id', $crawl->id)->where('url_hash', UrlNormalizer::hash(UrlNormalizer::resolve($event->url, $event->url) ?? $event->url))->value('inlinks');

        return $inlinks > 0 ? ["Kaldırılan sayfa HTTP {$state['status']} veriyor ama son taramada {$inlinks} iç link bu adrese gidiyordu: linkleri güncelleyin veya 301 yönlendirin"] : [];
    }

    private static function normalize(string $url): string
    {
        $parts = parse_url($url);

        return strtolower($parts['host'] ?? '').rtrim($parts['path'] ?? '', '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
