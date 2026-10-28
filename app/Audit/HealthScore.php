<?php

namespace App\Audit;

use App\Models\Crawl;
use Illuminate\Support\Collection;

/**
 * The 0-100 health score and how it was reached. Every rule that fires costs part of its severity weight: 30% for
 * appearing at all, the other 70% in proportion to the share of crawled pages it affects. So one broken page
 * matters, but a site-wide problem matters more.
 */
class HealthScore
{
    public const WEIGHTS = ['high' => 12, 'medium' => 6, 'low' => 2, 'info' => 0];

    public const BASE_SHARE = 0.3;

    /** Plain-language version of the formula for the panel and the client report. */
    public const EXPLANATION = 'Skor 100\'den başlar. Taramada çıkan her sorun türü, önemine göre en fazla şu kadar puan düşürür: '
        .'yüksek 12, orta 6, düşük 2 (bilgi notları puan düşürmez). Sorunun sitede bulunması bu puanın %30\'unu, '
        .'kalan %70\'i etkilediği sayfa oranıyla düşürür: tek sayfadaki sorun az, tüm siteyi etkileyen sorun tam puan düşürür. '
        .'80 ve üzeri iyi, 50-79 geliştirilmeli, 50 altı acil müdahale gerektirir.';

    public static function compute(Crawl $crawl): int
    {
        return (int) max(0, min(100, round(100 - self::deductions($crawl)->sum('points'))));
    }

    /**
     * Each rule's deduction, largest first.
     *
     * @return Collection<int, array{rule: string, title: string, severity: string, pages: int, points: float}>
     */
    public static function deductions(Crawl $crawl): Collection
    {
        $total = max(1, $crawl->pages()->where('state', 'done')->count());
        $catalog = RuleCatalog::all();

        return $crawl->issues()->selectRaw('rule, severity, COUNT(DISTINCT page_id) as pages')->groupBy('rule', 'severity')->get()
            ->map(fn ($row) => [
                'rule' => $row->rule,
                'title' => $catalog[$row->rule]['title'] ?? $row->rule,
                'severity' => $row->severity,
                'pages' => (int) $row->pages,
                'points' => (self::WEIGHTS[$row->severity] ?? 0) * (self::BASE_SHARE + (1 - self::BASE_SHARE) * min(1, $row->pages / $total)),
            ])
            ->filter(fn (array $row) => $row['points'] > 0)
            ->sortByDesc('points')->values();
    }
}
