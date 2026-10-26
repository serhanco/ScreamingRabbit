<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    public const STATUSES = [
        'open' => 'Açık',
        'doing' => 'Yapılıyor',
        'done' => 'Tamamlandı',
        'verified' => 'Doğrulandı',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'show_in_report' => 'boolean',
            'baseline' => 'array',
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
                $task->baseline = ['clicks' => $task->clicks, 'at' => now()->toDateString()];
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
