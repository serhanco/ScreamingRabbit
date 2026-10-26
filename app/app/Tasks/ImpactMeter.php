<?php

namespace App\Tasks;

use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Before/after impact of finished work (docs/09 Faz 3): once 28 days of Search Console data exist after a task
 * was marked done, the affected pages' clicks and impressions are compared with the 28 days before.
 */
class ImpactMeter
{
    public const DAYS = 28;

    public static function run(Project $project): int
    {
        $latest = DB::table('gsc_page_days')->where('project_id', $project->id)->max('day');
        if (! $latest) {
            return 0;
        }
        $cutoff = CarbonImmutable::parse($latest)->subDays(self::DAYS)->endOfDay();

        $measured = 0;
        Task::query()->where('project_id', $project->id)->whereNull('impact')->whereNotNull('done_at')
            ->where('done_at', '<=', $cutoff)->each(function (Task $task) use (&$measured) {
                $pages = $task->baseline['pages'] ?? [];
                if ($pages === []) {
                    return;
                }
                $done = CarbonImmutable::parse($task->done_at)->startOfDay();
                $before = self::sum($task->project_id, $pages, $done->subDays(self::DAYS), $done->subDay());
                $after = self::sum($task->project_id, $pages, $done->addDay(), $done->addDays(self::DAYS));

                $task->update(['impact' => [
                    'before_clicks' => $before->clicks, 'after_clicks' => $after->clicks,
                    'before_impressions' => $before->impressions, 'after_impressions' => $after->impressions,
                    'change' => $before->clicks > 0 ? round(($after->clicks - $before->clicks) / $before->clicks * 100) : null,
                    'measured_on' => now()->toDateString(),
                ]]);
                $measured++;
            });

        return $measured;
    }

    /**
     * One line for people: "Tıklama 120 → 168 (+%40)".
     */
    public static function label(?array $impact): ?string
    {
        if (! $impact) {
            return null;
        }
        $change = $impact['change'] === null ? '' : ' ('.($impact['change'] >= 0 ? '+' : '-').'%'.abs($impact['change']).')';

        return "Tıklama {$impact['before_clicks']} → {$impact['after_clicks']}{$change}";
    }

    /**
     * @param  array<int, string>  $pages  page hashes
     */
    private static function sum(int $projectId, array $pages, CarbonImmutable $from, CarbonImmutable $to): object
    {
        $row = collect(array_chunk($pages, 500))->map(fn (array $chunk) => DB::table('gsc_page_days')
            ->where('project_id', $projectId)->whereIn('page_hash', $chunk)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(impressions), 0) as impressions')->first());

        return (object) ['clicks' => (int) $row->sum('clicks'), 'impressions' => (int) $row->sum('impressions')];
    }
}
