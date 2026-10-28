<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** A project's health score after one crawl; the rows together are the health trend. */
class ProjectScore extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scored_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public static function record(Crawl $crawl): void
    {
        $severities = $crawl->issues()->groupBy('severity')->selectRaw('severity, COUNT(*) as n')->pluck('n', 'severity');

        static::updateOrCreate(['crawl_id' => $crawl->id], [
            'project_id' => $crawl->project_id,
            'scored_at' => $crawl->finished_at ?? now(),
            'health_score' => $crawl->health_score,
            'pages' => $crawl->pages()->where('state', 'done')->count(),
            'high' => (int) ($severities['high'] ?? 0),
            'medium' => (int) ($severities['medium'] ?? 0),
            'low' => (int) ($severities['low'] ?? 0),
        ]);
    }

    /**
     * The latest scores of a project, oldest first, as chart points; $until limits them to scores up to that moment (a report's crawl).
     *
     * @return Collection<int, array{date: string, score: int, high: int}>
     */
    public static function trend(int $projectId, int $limit = 12, ?\DateTimeInterface $until = null): Collection
    {
        return static::query()->where('project_id', $projectId)
            ->when($until, fn ($query) => $query->where('scored_at', '<=', $until))
            ->orderByDesc('scored_at')->orderByDesc('id')->limit($limit)->get()->reverse()->values()
            ->map(fn (self $row) => [
                'date' => $row->scored_at->timezone(config('app.timezone'))->format('d.m'),
                'score' => (int) $row->health_score,
                'high' => (int) $row->high,
            ]);
    }
}
