<?php

namespace App\Ai;

use App\Models\Report;
use App\Reports\CrawlReport;
use App\Reports\MonthlyReports;

/**
 * An AI-written summary for a monthly client report (docs/09 Faz 7, AI actions): the report's own numbers turned into a
 * few plain sentences for the client. It goes into the draft, where it can be edited; the client sees it only after
 * the report is approved.
 */
class ReportSummary
{
    private const SYSTEM = <<<'TXT'
        You write the summary at the top of a monthly SEO report that an agency sends to its client, a business owner who
        is not an SEO expert. Write in Turkish, in a warm, professional and plain tone, 120 to 200 words in two or three
        short paragraphs: how the site is doing overall (health score and its trend, Google search clicks and impressions
        when given, uptime when given), the two or three most important problems in everyday words with why they matter,
        and what was done or is planned. Use only the numbers in the data; never invent figures, causes or promises, and
        do not mention data that is missing. No headings, lists, markdown or greetings; plain paragraphs only.
        TXT;

    /** Writes the summary into the draft; returns why it could not when it fails. */
    public static function write(Report $report): ?string
    {
        if ($report->status !== 'draft' || $report->crawl === null) {
            return 'Özet yalnızca onay bekleyen raporlara yazılır.';
        }

        try {
            $result = Ai::ask('report_summary', self::SYSTEM, self::prompt($report), $report->project_id, 800);
        } catch (AiException $e) {
            return $e->getMessage();
        }
        $text = trim(preg_replace('/^#+\s.*$/m', '', $result->text) ?? '');
        if ($text === '') {
            Ai::discard($result);

            return 'AI boş cevap verdi; tekrar deneyin.';
        }
        MonthlyReports::setSummary($report, $text);

        return null;
    }

    private static function prompt(Report $report): string
    {
        $data = (new CrawlReport($report->crawl))->data();

        $facts = [
            'site' => $data['site'],
            'period' => $report->periodLabel(),
            'health_score' => $data['score'],
            'health_score_trend' => collect($data['trend'])->take(-6)->values()->all(),
            'pages_crawled' => $data['crawled'],
            'broken_pages' => $data['client_errors'] + $data['server_errors'],
            'top_problems' => collect($data['findings'])->take(8)->map(fn (array $f) => ['problem' => $f['title'], 'severity' => $f['severity_label'], 'pages' => $f['pages']])->all(),
            'google_search' => $data['gsc'] === null ? null : collect($data['gsc'])->except(['pages', 'queries'])->all(),
            'top_queries' => $data['gsc']['queries'] ?? null,
            'uptime_30_days' => $data['uptime'],
            'work' => $data['tasks'],
        ];

        return 'Report data (JSON):'."\n".json_encode(array_filter($facts, fn ($value) => $value !== null && $value !== []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
