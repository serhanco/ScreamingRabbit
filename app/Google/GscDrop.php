<?php

namespace App\Google;

use App\Models\AlertEvent;
use App\Models\Project;
use App\Monitoring\Alerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Once a day: compares a project's Search Console clicks of the last 7 days with the 7 days before and alerts on a
 * real drop (docs/08 §3.1). Small sites are skipped, where a few clicks either way mean nothing.
 */
class GscDrop
{
    public const MIN_CLICKS = 50;

    public const DROP_PERCENT = 30;

    public static function runAll(): int
    {
        $sent = 0;
        Project::query()->whereNotNull('gsc_property')->each(function (Project $project) use (&$sent) {
            $sent += self::check($project) ? 1 : 0;
        });

        return $sent;
    }

    public static function check(Project $project): bool
    {
        $latest = DB::table('gsc_page_days')->where('project_id', $project->id)->max('day');
        if ($latest === null) {
            return false;
        }
        $end = CarbonImmutable::parse($latest);
        $sum = fn (CarbonImmutable $from, CarbonImmutable $to) => (int) DB::table('gsc_page_days')->where('project_id', $project->id)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])->sum('clicks');
        $now = $sum($end->subDays(6), $end);
        $before = $sum($end->subDays(13), $end->subDays(7));

        if ($before < self::MIN_CLICKS || $now > $before * (1 - self::DROP_PERCENT / 100)) {
            return false;
        }
        // Once a week per project is enough; the drop stays visible on the opportunities page.
        if (AlertEvent::where('kind', 'gsc_drop')->where('project_id', $project->id)->where('created_at', '>=', now()->subDays(7))->exists()) {
            return false;
        }

        $percent = (int) round(($before - $now) / $before * 100);
        Alerts::send('gsc_drop', "Search Console tıklamaları %{$percent} düştü: {$project->name}",
            "Son 7 gün {$now} tıklama, önceki 7 gün {$before} (son veri günü {$end->format('d.m.Y')}).\nHangi sayfaların düştüğü projenin \"Search Console fırsatları\" sayfasında.", $project->id);

        return true;
    }
}
