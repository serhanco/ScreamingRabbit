<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_id', 'name', 'base_url', 'platform', 'crawl_settings', 'gsc_property'])]
class Project extends Model
{
    use HasFactory;

    public const PLATFORMS = [
        'wordpress' => 'WordPress',
        'generic' => 'Genel (diğer)',
    ];

    protected function casts(): array
    {
        return [
            'crawl_settings' => 'array',
            'gsc_synced_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        // Every new site is watched for downtime from the start (docs/08 §2.1: default target is the home page).
        static::created(fn (Project $project) => $project->monitors()->create(['url' => $project->base_url]));
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function crawls(): HasMany
    {
        return $this->hasMany(Crawl::class)->latest();
    }

    /**
     * Project crawl settings merged over the application defaults.
     *
     * @return array<string, mixed>
     */
    public function effectiveCrawlSettings(): array
    {
        // Blank form fields are stored as null/'' and must fall back to the default, while false stays a real value.
        $overrides = array_filter($this->crawl_settings ?? [], fn ($value) => $value !== null && $value !== '');

        return array_merge(config('screamingrabbit.crawl_defaults'), $overrides);
    }
}
