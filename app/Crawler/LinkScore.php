<?php

namespace App\Crawler;

use App\Models\Crawl;
use Illuminate\Support\Facades\DB;

/**
 * Internal PageRank over the crawled link graph, shown as a 0-100 logarithmic Link Score like Screaming Frog's.
 * Nofollow links pass nothing, redirects pass their score on to the target, and pages without outlinks spread
 * theirs over the whole site (the standard handling of dangling nodes).
 */
class LinkScore
{
    private const DAMPING = 0.85;

    private const ITERATIONS = 30;

    public static function compute(Crawl $crawl): void
    {
        $ids = [];
        $html = [];
        $out = [];
        foreach ($crawl->pages()->where('state', 'done')->select('id', 'url_hash', 'status_code', 'content_type', 'redirect_to')->cursor() as $page) {
            $ids[$page->url_hash] = $page->id;
            $out[$page->id] = [];
            if ($page->status_code === 200 && str_contains((string) $page->content_type, 'html')) {
                $html[$page->id] = true;
            } elseif ($page->redirect_to) {
                $out[$page->id]['r'] = sha1($page->redirect_to);
            }
        }
        if ($html === []) {
            return;
        }

        foreach ($out as $id => $targets) {
            if (isset($targets['r'])) {
                $out[$id] = isset($ids[$targets['r']]) ? [$ids[$targets['r']]] : [];
            }
        }
        foreach (DB::table('crawl_links')->where('crawl_id', $crawl->id)->where('nofollow', false)->select('from_page_id', 'to_hash')->distinct()->cursor() as $link) {
            $to = $ids[$link->to_hash] ?? null;
            if ($to !== null && $to !== $link->from_page_id && isset($out[$link->from_page_id])) {
                $out[$link->from_page_id][] = $to;
            }
        }

        $count = count($out);
        $rank = array_fill_keys(array_keys($out), 1 / $count);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $dangling = 0.0;
            $next = array_fill_keys(array_keys($out), 0.0);
            foreach ($out as $id => $targets) {
                if ($targets === []) {
                    $dangling += $rank[$id];

                    continue;
                }
                $share = $rank[$id] / count($targets);
                foreach ($targets as $to) {
                    $next[$to] += $share;
                }
            }
            $base = (1 - self::DAMPING) / $count + self::DAMPING * $dangling / $count;
            foreach ($next as $id => $value) {
                $next[$id] = $base + self::DAMPING * $value;
            }
            $rank = $next;
        }

        $rank = array_intersect_key($rank, $html);
        $min = log(min($rank));
        $spread = log(max($rank)) - $min;
        $byScore = [];
        foreach ($rank as $id => $value) {
            $score = $spread > 0 ? (int) round(1 + 99 * (log($value) - $min) / $spread) : 100;
            $byScore[$score][] = $id;
        }
        foreach ($byScore as $score => $pageIds) {
            foreach (array_chunk($pageIds, 1000) as $chunk) {
                DB::table('crawl_pages')->whereIn('id', $chunk)->update(['link_score' => $score]);
            }
        }
    }
}
