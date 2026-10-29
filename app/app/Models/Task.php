<?php

namespace App\Models;

use App\Google\GscInsights;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Task extends Model
{
    use LogsActivity;

    public const STATUSES = [
        'open' => 'Açık',
        'doing' => 'Yapılıyor',
        'done' => 'Tamamlandı',
        'verified' => 'Doğrulandı',
    ];

    private const MAX_PAGES = 2000;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'show_in_report' => 'boolean',
            'baseline' => 'array',
            'impact' => 'array',
            'done_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Marking a task done records the affected pages' Search Console numbers, to compare with 28 days later.
        static::saving(function (Task $task) {
            if ($task->isDirty('status') && $task->status === 'done') {
                $task->done_at = now();
                $task->baseline = ['clicks' => $task->clicks, 'at' => now()->toDateString(), 'pages' => $task->affectedPages()];
                $task->impact = null;
            }
        });
    }

    /**
     * Hashes of the pages this task is about, so their Search Console numbers can be compared later.
     *
     * @return array<int, string>
     */
    public function affectedPages(): array
    {
        [$source, $name] = explode(':', $this->key, 2) + [1 => ''];

        if ($source === 'rule') {
            $crawl = Crawl::query()->where('project_id', $this->project_id)->where('status', 'completed')->latest('id')->first();

            return $crawl ? DB::table('crawl_issues')->where('crawl_issues.crawl_id', $crawl->id)->where('crawl_issues.rule', $name)
                ->join('crawl_pages', 'crawl_pages.id', '=', 'crawl_issues.page_id')
                ->distinct()->limit(self::MAX_PAGES)->pluck('crawl_pages.url_hash')->all() : [];
        }

        if ($source === 'gsc' && $this->project?->gsc_property) {
            $items = (new GscInsights($this->project))->all()[$name] ?? collect();

            return $items->flatMap(fn ($item) => isset($item->urls) ? collect($item->urls)->pluck('page')->all() : [$item->page_hash ?? $item->url_hash ?? $item->page ?? $item->url ?? null])
                ->filter()->map(fn (string $page) => strlen($page) === 40 && ! str_contains($page, '/') ? $page : sha1($page))
                ->unique()->take(self::MAX_PAGES)->values()->all();
        }

        return [];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function activityLabel(): string
    {
        return 'İş: '.$this->title;
    }

    /** @return array<int, string> */
    public function activityOnly(): array
    {
        return ['status', 'note', 'due_on', 'show_in_report'];
    }
}
