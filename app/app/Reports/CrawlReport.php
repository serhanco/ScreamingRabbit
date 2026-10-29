<?php

namespace App\Reports;

use App\Audit\HealthScore;
use App\Audit\RuleCatalog;
use App\Crawler\BotAccess;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\ProjectScore;
use App\Models\Task;
use App\Tasks\ImpactMeter;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
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

    /** @param  string|null  $summary  the approved summary of a monthly report, shown at the top */
    public function __construct(private Crawl $crawl, private ?string $summary = null) {}

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
            'deductions' => HealthScore::deductions($crawl)->take(5)->map(fn (array $row) => $row + [
                'severity_label' => RuleCatalog::SEVERITIES[$row['severity']] ?? $row['severity'],
            ])->all(),
            'crawlability' => $this->crawlability(),
            'trend' => ProjectScore::trend($crawl->project_id, 12, $crawl->finished_at ?? now())->all(),
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
            'gsc' => $this->searchConsole(),
            'uptime' => $this->uptime(),
            'summary' => $this->summary,
            'brand' => config('screamingrabbit.brand'),
            'branding' => ReportBranding::current(),
        ];
    }

    /**
     * Google search performance: the last 28 days against the 28 before, plus the pages and queries that bring the
     * most clicks. Null when the project has no Search Console data.
     *
     * @return array<string, mixed>|null
     */
    private function searchConsole(): ?array
    {
        $projectId = $this->crawl->project_id;
        $latest = DB::table('gsc_page_days')->where('project_id', $projectId)->max('day');
        if (! $latest) {
            return null;
        }
        $end = CarbonImmutable::parse($latest);
        $window = fn (CarbonImmutable $to) => DB::table('gsc_page_days')->where('project_id', $projectId)
            ->whereBetween('day', [$to->subDays(27)->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(impressions), 0) as impressions, SUM(position * impressions) / NULLIF(SUM(impressions), 0) as position')
            ->first();
        $now = $window($end);
        $before = $window($end->subDays(28));
        $change = fn ($a, $b) => $b > 0 ? (int) round(($a - $b) / $b * 100) : null;

        return [
            'from' => $end->subDays(27)->format('d.m.Y'),
            'to' => $end->format('d.m.Y'),
            'clicks' => (int) $now->clicks,
            'impressions' => (int) $now->impressions,
            'ctr' => $now->impressions > 0 ? round($now->clicks / $now->impressions * 100, 1) : null,
            'position' => $now->position !== null ? round((float) $now->position, 1) : null,
            'clicks_change' => $change((int) $now->clicks, (int) $before->clicks),
            'impressions_change' => $change((int) $now->impressions, (int) $before->impressions),
            'pages' => DB::table('gsc_page_days')->where('project_id', $projectId)
                ->whereBetween('day', [$end->subDays(27)->toDateString(), $end->toDateString()])
                ->groupBy('page_hash')->selectRaw('MIN(page) as page, SUM(clicks) as clicks, SUM(impressions) as impressions')
                ->orderByDesc('clicks')->limit(10)->get()->all(),
            'queries' => DB::table('gsc_queries')->where('project_id', $projectId)
                ->groupBy('query')->selectRaw('query, SUM(clicks) as clicks, SUM(impressions) as impressions, MIN(position) as position')
                ->orderByDesc('clicks')->limit(10)->get()->all(),
        ];
    }

    /**
     * Availability over the last 30 days from the project's uptime monitors. Null when nothing is monitored.
     *
     * @return array<int, array{url: string, uptime: ?float, incidents: int, downtime: string, avg_ms: ?int}>|null
     */
    private function uptime(): ?array
    {
        $monitors = Monitor::query()->where('project_id', $this->crawl->project_id)->get();
        if ($monitors->isEmpty()) {
            return null;
        }
        $since = now()->subDays(30);

        return $monitors->map(function (Monitor $monitor) use ($since) {
            $daily = DB::table('monitor_daily')->where('monitor_id', $monitor->id)->where('day', '>=', $since->toDateString())
                ->selectRaw('SUM(checks) as checks, SUM(up_checks) as up, AVG(avg_ms) as ms')->first();
            $incidents = Incident::query()->where('monitor_id', $monitor->id)->where('started_at', '>=', $since)->get();
            $minutes = (int) $incidents->sum(fn (Incident $incident) => $incident->started_at->diffInMinutes($incident->ended_at ?? now()));

            return [
                'url' => $monitor->url,
                'uptime' => $daily && $daily->checks > 0 ? round($daily->up / $daily->checks * 100, 2) : null,
                'incidents' => $incidents->count(),
                'downtime' => $minutes >= 60 ? intdiv($minutes, 60).' sa '.($minutes % 60).' dk' : $minutes.' dk',
                'avg_ms' => $daily && $daily->ms !== null ? (int) round((float) $daily->ms) : null,
            ];
        })->all();
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
     * Can search engines reach and index the site: robots.txt, sitemap, blocked and noindex pages, depth, bot access.
     *
     * @return array<int, array{label: string, value: string, state: string}>
     */
    private function crawlability(): array
    {
        $crawl = $this->crawl;
        $lines = [];
        $lines[] = ['label' => 'robots.txt dosyası', 'value' => filled($crawl->robots_txt) ? 'Var' : 'Yok', 'state' => filled($crawl->robots_txt) ? 'ok' : 'warn'];
        $lines[] = ['label' => 'XML site haritası (sitemap)', 'value' => $crawl->sitemap_found ? 'Bulundu' : 'Bulunamadı', 'state' => $crawl->sitemap_found ? 'ok' : 'warn'];

        $blocked = $crawl->pages()->where('state', 'blocked')->count();
        $lines[] = ['label' => 'robots.txt\'nin engellediği adresler', 'value' => (string) $blocked, 'state' => $blocked === 0 ? 'ok' : 'warn'];

        $html = $crawl->pages()->where('state', 'done')->where('status_code', 200)->where('content_type', 'like', '%html%');
        $total = (clone $html)->count();
        if ($total > 0) {
            $noindex = (clone $html)->where(fn ($q) => $q->where('meta_robots', 'like', '%noindex%')->orWhere('x_robots', 'like', '%noindex%'))->count();
            $canonicalElsewhere = (clone $html)->whereNotNull('canonical')->where('canonical', '!=', '')->whereColumn('canonical', '!=', 'url')->count();
            $indexable = (clone $html)->get(['url', 'meta_robots', 'x_robots', 'canonical', 'state', 'status_code', 'content_type'])
                ->filter->isIndexable()->count();
            $share = (int) round($indexable / $total * 100);
            $lines[] = ['label' => 'İndekslenebilir sayfalar (200, noindex yok, canonical kendisi)', 'value' => "%{$share} ({$indexable} / {$total})", 'state' => $share >= 80 ? 'ok' : 'warn'];
            $lines[] = ['label' => 'Noindex sayfalar', 'value' => (string) $noindex, 'state' => $noindex === 0 ? 'ok' : 'warn'];
            $lines[] = ['label' => 'Canonical\'ı başka adresi gösteren sayfalar', 'value' => (string) $canonicalElsewhere, 'state' => 'ok'];
            if ($crawl->sitemap_found) {
                $missing = (clone $html)->where('in_sitemap', false)->count();
                $lines[] = ['label' => 'Site haritasında olmayan sayfalar', 'value' => (string) $missing, 'state' => $missing === 0 ? 'ok' : 'warn'];
            }
            $deep = (clone $html)->where('depth', '>', 3)->count();
            $lines[] = ['label' => 'Ana sayfadan 3 tıktan derindeki sayfalar', 'value' => (string) $deep, 'state' => $deep === 0 ? 'ok' : 'warn'];
        }

        $search = collect($crawl->bot_access ?? [])->where('kind', 'search');
        if ($search->isNotEmpty()) {
            $open = $search->filter(fn ($bot) => $bot['robots_allowed'] && $bot['fetch'] !== 'refused')->count();
            $lines[] = ['label' => 'Arama motoru botları (Google, Bing…)', 'value' => "{$open} / {$search->count()} erişebiliyor", 'state' => $open === $search->count() ? 'ok' : 'bad'];
        }

        return $lines;
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
            $average = (clone $pages)->whereNotNull('ai_score')->avg('ai_score');
            if ($average !== null) {
                $average = (int) round($average);
                $lines[] = ['label' => 'AI hazırlık skoru (sayfa ortalaması)', 'value' => "{$average} / 100", 'state' => $average >= 80 ? 'ok' : ($average >= 50 ? 'warn' : 'bad')];
            }
            $articles = (clone $pages)->where(fn ($q) => $q->where('is_article', true)->orWhere('wp_type', 'post'));
            $articleCount = (clone $articles)->count();
            if ($articleCount > 0) {
                $complete = (clone $articles)->where('has_author', true)->where(fn ($q) => $q->whereNotNull('published_at')->orWhereNotNull('modified_at'))->count();
                $lines[] = ['label' => 'Yazarı ve tarihi görünen yazılar', 'value' => "{$complete} / {$articleCount}", 'state' => $complete === $articleCount ? 'ok' : ($complete > 0 ? 'warn' : 'bad')];
            }
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

    public function html(bool $forPdf = false, ?string $pdfUrl = null): string
    {
        return view('reports.crawl', ['report' => $this->data(), 'forPdf' => $forPdf, 'pdfUrl' => $pdfUrl])->render();
    }

    public function pdf(): string
    {
        return self::renderPdf($this->html(true));
    }

    /** Turns report HTML (rendered with $forPdf) into a PDF; archived monthly reports use it on their stored HTML. */
    public static function renderPdf(string $html): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
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
