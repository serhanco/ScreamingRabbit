<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WatchedPage extends Model
{
    public const FREQUENCIES = ['hourly' => 'Saatte bir', 'daily' => 'Günde bir'];

    /** At most this many critical pages per project, so checks stay light on shared hosting. */
    public const MAX_PER_PROJECT = 50;

    public const FIELDS = [
        'status' => 'Durum kodu',
        'location' => 'Yönlendirme hedefi',
        'noindex' => 'Noindex',
        'canonical' => 'Canonical',
        'title' => 'Title',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'state' => 'array',
            'last_checked_at' => 'datetime',
            'last_changed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(WatchedPageChange::class)->latest('detected_at');
    }

    public function isDue(): bool
    {
        $every = $this->frequency === 'daily' ? 24 * 60 : 60;

        return $this->last_checked_at === null || $this->last_checked_at->lte(now()->subMinutes($every)->addMinutes(2));
    }
}
