<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlPage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'in_sitemap' => 'boolean',
            'wordpress_hint' => 'boolean',
            'js_shell' => 'boolean',
            'schema_problems' => 'array',
            'hreflang' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function crawl(): BelongsTo
    {
        return $this->belongsTo(Crawl::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(CrawlIssue::class, 'page_id');
    }

    /** A 200 HTML page that search engines may index (no noindex, canonical absent or pointing at itself). */
    public function isIndexable(): bool
    {
        return $this->isHtmlOk()
            && ! str_contains(strtolower((string) $this->meta_robots), 'noindex')
            && ! str_contains(strtolower((string) $this->x_robots), 'noindex')
            && ($this->canonical === null || $this->canonical === '' || $this->canonical === $this->url);
    }

    public function isHtmlOk(): bool
    {
        return $this->state === 'done'
            && $this->status_code === 200
            && str_contains((string) $this->content_type, 'html');
    }
}
