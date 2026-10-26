<?php

namespace App\Reports;

use App\Audit\RuleCatalog;
use App\Crawler\BotAccess;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\Task;
use App\Tasks\ImpactMeter;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;

/**
 * Builds the client report of a crawl as plain data, and renders it as HTML (web link) or PDF.
 */
class CrawlReport
{
    private const SAMPLE_URLS = 5;

    /** WordPress checks shown as a pass/fail list in the report; each passes when its rule raised nothing. */
    private const WP_CHECKS = ['wp_core_outdated', 'wp_plugin_outdated', 'wp_user_enumeration', 'wp_xmlrpc_enabled', 'wp_default_files',
        'wp_debug_log_public', 'wp_directory_listing', 'wp_config_backup_exposed', 'wp_login_http', 'wp_search_indexable', 'wp_double_sitemap'];

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
            'bots' => BotAccess::present($crawl->bot_access),
            'ai' => $this->aiVisibility($groups->pluck('rule')->all()),
            'wordpress' => $this->wordPress($groups->pluck('rule')->all()),
            'tasks' => $this->tasks(),
            'brand' => config('screamingrabbit.brand'),
        ];
    }

    /**
     * Work items the operator chose to show the client, grouped as done, in progress and planned.
     *
     * @return array<string, array<int, array{title: string, why: string, date: ?string, verified: bool, impact: ?string}>>
     */
    private function tasks(): array
    {
        $groups = ['done' => ['done', 'verified'], 'doing' => ['doing'], 'open' => ['open']];
        $tasks = Task::query()->where('project_id', $this->crawl->project_id)->where('show_in_report', true)
            ->orderByDesc('score')->get();

        return collect($groups)->map(fn (array $statuses) => $tasks->whereIn('status', $statuses)->map(fn (Task $task) => [
            'title' => $task->title,
            'why' => $task->why,
            'date' => ($task->verified_at ?? $task->done_at)?->timezone(config('app.timezone'))->format('d.m.Y'),
            'verified' => $task->status === 'verified',
            'impact' => ImpactMeter::label($task->impact),
        ])->values()->all())->filter()->all();
    }

    /**
     * The signals AI search engines rely on, each as a line with a state: ok, warn or bad.
     *
     * @param  array<int, string>  $rules  rules raised in this crawl
     * @return array<int, array{label: string, value: string, state: string}>
     */
    private function aiVisibility(array $rules): array
    {
        $lines = [];
        $bots = collect($this->crawl->bot_access ?? []);
        foreach (['search' => 'Arama motoru botları', 'ai_search' => 'AI arama botları (ChatGPT, Perplexity, Claude…)', 'ai_training' => 'AI eğitim botları'] as $kind => $label) {
            $group = $bots->where('kind', $kind);
            if ($group->isEmpty()) {
                continue;
            }
            $open = $group->filter(fn ($bot) => $bot['robots_allowed'] && $bot['fetch'] !== 'refused')->count();
            $state = $open === $group->count() ? 'ok' : ($kind === 'ai_training' ? 'warn' : ($open === 0 ? 'bad' : 'warn'));
            $lines[] = ['label' => $label, 'value' => "{$open} / {$group->count()} erişebiliyor", 'state' => $state];
        }

        $llms = $this->crawl->llms_txt['status'] ?? null;
        if ($llms !== null) {
            $value = (['ok' => 'Var', 'invalid' => 'Var, biçimi hatalı', 'missing' => 'Yok'][$llms] ?? $llms).(($this->crawl->llms_txt['full'] ?? false) ? ' · llms-full.txt de var' : '');
            $lines[] = ['label' => 'llms.txt dosyası', 'value' => $value, 'state' => $llms === 'ok' ? 'ok' : 'warn'];
        }

        $pages = $this->crawl->pages()->where('status_code', 200)->where('content_type', 'like', '%html%');
        $total = (clone $pages)->count();
        if ($total > 0) {
            $lines[] = ['label' => 'Ana sayfada kurum/kişi şeması', 'value' => in_array('home_missing_organization_schema', $rules, true) ? 'Yok' : 'Var',
                'state' => in_array('home_missing_organization_schema', $rules, true) ? 'bad' : 'ok'];
            $withSchema = (clone $pages)->whereNotNull('schema_types')->where('schema_types', '!=', '')->count();
            $share = (int) round($withSchema / $total * 100);
            $lines[] = ['label' => 'Yapısal veri (JSON-LD) olan sayfalar', 'value' => "%{$share} ({$withSchema} / {$total})", 'state' => $share >= 80 ? 'ok' : ($share > 0 ? 'warn' : 'bad')];
            $jsOnly = (clone $pages)->where('js_shell', true)->count();
            $lines[] = ['label' => 'JavaScript olmadan okunabilen sayfalar', 'value' => ($total - $jsOnly)." / {$total}", 'state' => $jsOnly === 0 ? 'ok' : 'bad'];
            $broken = (clone $pages)->where('schema_errors', '>', 0)->count();
            if ($broken > 0) {
                $lines[] = ['label' => 'Bozuk JSON-LD olan sayfalar', 'value' => (string) $broken, 'state' => 'bad'];
            }
        }

        return $lines;
    }

    /**
     * Version, plugins and a pass/fail security list; null when the site is not WordPress or the checks did not run.
     *
     * @param  array<int, string>  $rules  rules raised in this crawl
     * @return array<string, mixed>|null
     */
    private function wordPress(array $rules): ?array
    {
        $checks = $this->crawl->wp_checks ?? [];
        $probes = $checks['probes'] ?? [];
        if ($probes === []) {
            return null;
        }

        $value = fn (string $key) => $probes[$key]['value'] ?? null;
        $version = $value('feed') ?? $value('readme');
        $catalog = RuleCatalog::all();

        return [
            'version' => $version,
            'latest' => $value('core_latest'),
            'plugins' => collect($checks['inventory']['plugins'] ?? [])->map(fn ($seen, $slug) => [
                'slug' => $slug,
                'seen' => $seen !== $version ? $seen : null,
                'latest' => $value('plugin:'.$slug),
            ])->values()->all(),
            'themes' => array_keys($checks['inventory']['themes'] ?? []),
            'checks' => collect(self::WP_CHECKS)->map(fn (string $rule) => [
                'title' => $catalog[$rule]['title'] ?? $rule,
                'passed' => ! in_array($rule, $rules, true),
            ])->all(),
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
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii($this->crawl->project->name))) ?: 'rapor';

        return 'seo-raporu-'.trim($slug, '-').'-'.now()->format('Y-m-d').'.pdf';
    }
}
