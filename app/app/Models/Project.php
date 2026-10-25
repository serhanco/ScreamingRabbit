<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_id', 'name', 'base_url', 'platform', 'crawl_settings'])]
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
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
