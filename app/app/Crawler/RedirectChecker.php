<?php

namespace App\Crawler;

use App\Models\RedirectCheck;
use Illuminate\Support\Facades\Http;

/**
 * Works through pending migration checks a few URLs at a time (called every minute by the scheduler), following
 * each old URL's redirects hop by hop.
 */
class RedirectChecker
{
    private const URLS_PER_RUN = 15;

    private const MAX_HOPS = 6;

    private const TIMEOUT_SECONDS = 10;

    public static function runDue(): int
    {
        $budget = self::URLS_PER_RUN;
        foreach (RedirectCheck::query()->where('status', 'pending')->orderBy('id')->get() as $check) {
            $rows = $check->rows;
            foreach ($rows as $i => $row) {
                if ($budget === 0) {
                    break;
                }
                if ($row['state'] === 'pending') {
                    $rows[$i] = array_merge($row, self::follow($row['url'], $row['expected'] ?? null));
                    $budget--;
                }
            }
            $done = ! collect($rows)->contains(fn (array $row) => $row['state'] === 'pending');
            $check->update(['rows' => $rows, 'status' => $done ? 'done' : 'pending']);
            if ($budget === 0) {
                break;
            }
        }

        return self::URLS_PER_RUN - $budget;
    }

    /**
     * @return array<string, mixed>
     */
    public static function follow(string $url, ?string $expected): array
    {
        $hops = [];
        $current = $url;
        $seen = [];
        $userAgent = config('screamingrabbit.crawl_defaults.user_agent');

        while (true) {
            if (isset($seen[$current]) || count($hops) >= self::MAX_HOPS) {
                return ['state' => 'done', 'hops' => $hops, 'final_url' => $current, 'final_status' => null, 'verdict' => 'loop'];
            }
            $seen[$current] = true;

            try {
                $response = Http::withUserAgent($userAgent)->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->get($current);
            } catch (\Throwable) {
                return ['state' => 'done', 'hops' => $hops, 'final_url' => $current, 'final_status' => null, 'verdict' => 'error'];
            }

            $status = $response->status();
            $location = $status >= 300 && $status < 400 ? UrlNormalizer::resolve((string) $response->header('Location'), $current) : null;
            if ($location === null) {
                return ['state' => 'done', 'hops' => $hops, 'final_url' => $current, 'final_status' => $status, 'verdict' => self::verdict($url, $hops, $current, $status, $expected)];
            }
            $hops[] = ['url' => $current, 'status' => $status];
            $current = $location;
        }
    }

    /**
     * @param  array<int, array{url: string, status: int}>  $hops
     */
    private static function verdict(string $url, array $hops, string $final, int $status, ?string $expected): string
    {
        $normal = fn (string $u) => rtrim(strtolower($u), '/');

        return match (true) {
            $status >= 400 => 'broken',
            $expected !== null && $normal($final) !== $normal($expected) => 'wrong_target',
            $hops === [] => 'same',
            collect($hops)->contains(fn (array $hop) => in_array($hop['status'], [302, 303, 307], true)) => 'temporary',
            count($hops) > 1 => 'chain',
            default => 'ok',
        };
    }
}
