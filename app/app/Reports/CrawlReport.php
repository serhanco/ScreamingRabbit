<?php

namespace App\Reports;

use App\Audit\RuleCatalog;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Builds the client report of a crawl as plain data, and renders it as HTML (web link) or PDF.
 */
class CrawlReport
{
    private const SAMPLE_URLS = 5;

    public function __construct(private Crawl $crawl) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $crawl = $this->crawl->loadMissing('project.client');
        $catalog = RuleCatalog::all();
        $severityOrder = ['high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

        $groups = $crawl->issues()
            ->selectRaw('rule, severity, COUNT(DISTINCT page_id) as pages')
            ->groupBy('rule', 'severity')
            ->get()
            ->sortBy(fn ($row) => [$severityOrder[$row->severity] ?? 9, -$row->pages, $row->rule]);

        $findings = $groups->map(function ($row) use ($crawl, $catalog) {
            $rule = $catalog[$row->rule] ?? ['title' => $row->rule, 'description' => '', 'fix' => '', 'category' => 'onpage'];

            $samples = CrawlIssue::query()
                ->where('crawl_issues.crawl_id', $crawl->id)
                ->where('crawl_issues.rule', $row->rule)
                ->join('crawl_pages', 'crawl_pages.id', '=', 'crawl_issues.page_id')
                ->orderBy('crawl_pages.id')
                ->limit(self::SAMPLE_URLS)
                ->pluck('crawl_pages.url')
                ->all();

            return [
                'rule' => $row->rule,
                'title' => $rule['title'],
                'description' => $rule['description'],
                'fix' => $rule['fix'],
                'category' => RuleCatalog::CATEGORIES[$rule['category']] ?? '',
                'severity' => $row->severity,
                'severity_label' => RuleCatalog::SEVERITIES[$row->severity] ?? $row->severity,
                'pages' => (int) $row->pages,
                'samples' => $samples,
            ];
        })->values()->all();

        $pages = $crawl->pages();

        return [
            'project' => $crawl->project->name,
            'client' => $crawl->project->client->name,
            'site' => $crawl->project->base_url,
            'date' => ($crawl->finished_at ?? $crawl->started_at ?? $crawl->created_at)->timezone(config('app.timezone'))->format('d.m.Y'),
            'score' => $crawl->health_score,
            'crawled' => (clone $pages)->where('state', 'done')->count(),
            'ok' => (clone $pages)->where('status_code', 200)->count(),
            'redirects' => (clone $pages)->whereBetween('status_code', [300, 399])->count(),
            'client_errors' => (clone $pages)->whereBetween('status_code', [400, 499])->count(),
            'server_errors' => (clone $pages)->where('status_code', '>=', 500)->count(),
            'by_severity' => collect(RuleCatalog::SEVERITIES)->map(fn (string $label, string $key) => [
                'label' => $label,
                'count' => (int) $crawl->issues()->where('severity', $key)->count(),
            ])->all(),
            'findings' => $findings,
            'bots' => \App\Crawler\BotAccess::present($crawl->bot_access),
            'brand' => config('screamingrabbit.brand'),
        ];
    }

    public function html(bool $forPdf = false): string
    {
        return view('reports.crawl', ['report' => $this->data(), 'forPdf' => $forPdf])->render();
    }

    public function pdf(): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html(true), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(\Illuminate\Support\Str::ascii($this->crawl->project->name))) ?: 'rapor';

        return 'seo-raporu-'.trim($slug, '-').'-'.now()->format('Y-m-d').'.pdf';
    }
}
