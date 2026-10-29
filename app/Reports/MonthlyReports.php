<?php

namespace App\Reports;

use App\Models\Crawl;
use App\Models\Project;
use App\Models\Report;
use App\Monitoring\Alerts;
use Carbon\CarbonImmutable;

/**
 * Prepares last month's client report for every project at the start of each month (docs/09 Faz 4). Reports wait as
 * drafts until someone approves them; nothing reaches the client on its own.
 */
class MonthlyReports
{
    /** Projects whose latest crawl is older than this get no automatic report: there is nothing new to say. */
    private const MAX_CRAWL_AGE_DAYS = 45;

    public static function generateDue(): int
    {
        $today = CarbonImmutable::now();
        // The first week of the month only: nightly crawls of the 1st have finished, and a missed day catches up.
        if ($today->day > 7) {
            return 0;
        }
        $period = $today->subMonthNoOverflow()->format('Y-m');

        $made = 0;
        Project::query()->whereDoesntHave('reports', fn ($q) => $q->where('period', $period))->each(function (Project $project) use ($period, &$made) {
            $crawl = Crawl::query()->where('project_id', $project->id)->where('status', 'completed')
                ->where('finished_at', '>=', now()->subDays(self::MAX_CRAWL_AGE_DAYS))->latest('id')->first();
            if (! $crawl) {
                return;
            }
            $report = self::make($project, $crawl, $period);
            Alerts::send('report_ready', "Aylık rapor onay bekliyor: {$project->name}", "{$report->periodLabel()} raporu hazır. Panelde \"Müşteri raporları\" sayfasından kontrol edip onayla.", $project->id);
            $made++;
        });

        return $made;
    }

    /** Freezes the crawl's report as the project's report for the period, replacing an unapproved draft. */
    public static function make(Project $project, Crawl $crawl, string $period): Report
    {
        $source = new CrawlReport($crawl);

        return Report::updateOrCreate(['project_id' => $project->id, 'period' => $period], [
            'crawl_id' => $crawl->id,
            'status' => 'draft',
            'health_score' => $crawl->health_score,
            'summary' => null,
            'html' => $source->html(false, Report::PDF_URL_PLACEHOLDER),
            'pdf_html' => $source->html(true),
            'token' => null,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /** Sets a draft's summary and renders the draft again with it; approved reports stay as they were sent. */
    public static function setSummary(Report $report, ?string $summary): bool
    {
        if ($report->status !== 'draft' || $report->crawl === null) {
            return false;
        }
        $summary = trim((string) $summary) === '' ? null : mb_substr(trim((string) $summary), 0, 3000);
        $source = new CrawlReport($report->crawl, $summary);
        $report->update(['summary' => $summary, 'html' => $source->html(false, Report::PDF_URL_PLACEHOLDER), 'pdf_html' => $source->html(true)]);

        return true;
    }
}
