<?php

namespace App\Tasks;

use App\Audit\RuleCatalog;
use App\Google\GscInsights;
use App\Google\GscSync;
use App\Models\Crawl;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each project's task list in step with its latest crawl findings and Search Console analyses (docs/04 §4.3).
 * A task is never duplicated: the same problem updates its row, a solved one is verified, a returning one reopens.
 */
class TaskBuilder
{
    private const WEIGHTS = ['high' => 10, 'medium' => 5, 'low' => 2, 'info' => 1];

    private const GSC = [
        'low_ctr' => ['Düşük tıklama oranlı sayfaların title/description\'ını yeniden yaz', 'medium'],
        'opportunities' => ['Fırsat sorgularındaki sayfaları güçlendir', 'medium'],
        'cannibalization' => ['Aynı sorguda yarışan sayfaları birleştir', 'medium'],
        'declining' => ['Tıklaması düşen sayfaları incele', 'high'],
        'orphans' => ['Yetim sayfalara iç link ver', 'low'],
        'zombies' => ['Zombi sayfaları birleştir, iyileştir veya noindex yap', 'low'],
        'index' => ['Google\'ın dizine eklemediği sayfaları düzelt', 'high'],
    ];

    public static function sync(Project $project): void
    {
        $crawl = Crawl::query()->where('project_id', $project->id)->where('status', 'completed')->latest('id')->first();
        $found = [];

        if ($crawl) {
            $clicks = filled($project->gsc_property) ? GscSync::last28($project->id) : null;
            $catalog = RuleCatalog::all();
            $rows = DB::table('crawl_issues')->where('crawl_issues.crawl_id', $crawl->id)
                ->join('crawl_pages', 'crawl_pages.id', '=', 'crawl_issues.page_id')
                ->when($clicks, fn ($q) => $q->leftJoinSub($clicks, 'gsc', 'gsc.page_hash', '=', 'crawl_pages.url_hash'))
                ->groupBy('crawl_issues.rule', 'crawl_issues.severity')
                ->selectRaw('crawl_issues.rule, crawl_issues.severity, COUNT(DISTINCT crawl_issues.page_id) as pages, '.($clicks ? 'COALESCE(SUM(gsc.gsc_clicks), 0)' : '0').' as clicks')
                ->get();

            foreach ($rows as $row) {
                // Informational findings are context, not work.
                if ($row->severity === 'info') {
                    continue;
                }
                $rule = $catalog[$row->rule] ?? ['title' => $row->rule];
                $why = "{$row->pages} sayfayı etkiliyor".($row->clicks > 0 ? "; bu sayfalar son 28 günde {$row->clicks} tıklama aldı." : '.');
                $found[] = self::upsert($project, 'rule:'.$row->rule, 'audit', $rule['title'], $why, $row->severity, (int) $row->pages, (int) $row->clicks);
            }
        }

        if (filled($project->gsc_property)) {
            foreach ((new GscInsights($project))->all() as $list => $items) {
                if ($items->isEmpty() || ! isset(self::GSC[$list])) {
                    continue;
                }
                [$title, $severity] = self::GSC[$list];
                $clicks = (int) $items->sum(fn ($item) => $item->gsc_clicks ?? $item->clicks ?? $item->before_clicks ?? 0);
                $found[] = self::upsert($project, 'gsc:'.$list, 'gsc', $title, $items->count().' sayfa/sorgu; ayrıntı "Search Console fırsatları" sayfasında.', $severity, $items->count(), $clicks);
            }
        }

        // Problems no longer found are solved: verified, whether or not someone marked them done first.
        Task::query()->where('project_id', $project->id)->whereNotIn('id', $found)->whereIn('status', ['open', 'doing', 'done'])
            ->when(! $crawl, fn ($q) => $q->where('source', '!=', 'audit'))
            ->when(blank($project->gsc_property), fn ($q) => $q->where('source', '!=', 'gsc'))
            ->each(fn (Task $task) => $task->update(['status' => 'verified', 'verified_at' => now()]));
    }

    private static function upsert(Project $project, string $key, string $source, string $title, string $why, string $severity, int $affected, int $clicks): int
    {
        // Importance × pages affected × traffic on them, on a log scale so one huge factor does not drown the others.
        $score = (int) round((self::WEIGHTS[$severity] ?? 1) * (1 + log(1 + $affected)) * (1 + log(1 + $clicks)) * 10);

        $task = Task::query()->firstOrNew(['project_id' => $project->id, 'key' => $key]);
        $task->fill(compact('source', 'title', 'why', 'severity', 'affected', 'clicks', 'score'));
        if ($task->exists && $task->status === 'verified') {
            $task->fill(['status' => 'open', 'verified_at' => null, 'note' => trim(($task->note ? $task->note."\n" : '').now()->format('d.m.Y').': sorun yeniden görüldü.')]);
        }
        $task->save();

        return $task->id;
    }
}
