<?php

namespace App\Performance;

use App\Models\Crawl;

/**
 * Mobile speed of a project over its recent crawls: average Lighthouse score of the sample pages and the home page's
 * real-user Core Web Vitals, so a slowdown after a theme or plugin change shows up.
 */
class PageSpeedTrend
{
    public const CRAWLS = 8;

    /**
     * Oldest first, ending with the given crawl.
     *
     * @return array<int, array{crawl_id: int, date: string, average: ?int, home: ?int, field: ?array<string, mixed>, poor: int}>
     */
    public static function upTo(Crawl $crawl): array
    {
        $crawls = Crawl::query()->where('project_id', $crawl->project_id)->where('status', 'completed')
            ->where('id', '<=', $crawl->id)->whereNotNull('pagespeed')->orderByDesc('id')->limit(self::CRAWLS)->get(['id', 'finished_at', 'pagespeed']);

        return $crawls->reverse()->map(function (Crawl $item) {
            $done = collect($item->pagespeed)->where('state', 'done');
            $scores = $done->pluck('score')->filter(fn ($score) => $score !== null);
            $home = $done->first(fn (array $row) => ($row['type'] ?? null) === 'home') ?? $done->first();

            return [
                'crawl_id' => $item->id,
                'date' => $item->finished_at?->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m.Y') ?? '-',
                'average' => $scores->isEmpty() ? null : (int) round($scores->avg()),
                'home' => $home['score'] ?? null,
                'field' => $home['field'] ?? null,
                'poor' => $done->filter(fn (array $row) => $row['field'] !== null && in_array('poor', PageSpeed::ratings($row['field']), true))->count(),
            ];
        })->filter(fn (array $row) => $row['average'] !== null)->values()->all();
    }
}
