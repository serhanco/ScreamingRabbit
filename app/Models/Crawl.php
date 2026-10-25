<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Crawl extends Model
{
    public const STATUSES = [
        'pending' => 'Sırada',
        'running' => 'Taranıyor',
        'completed' => 'Tamamlandı',
        'failed' => 'Hata',
        'cancelled' => 'Durduruldu',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sitemap_found' => 'boolean',
            'bot_access' => 'array',
            'llms_txt' => 'array',
            'wp_checks' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(CrawlPage::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(CrawlIssue::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(CrawlImage::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }
}
