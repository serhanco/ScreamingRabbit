<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Crawl extends Model
{
    use LogsActivity;

    public const STATUSES = [
        'pending' => 'Sırada',
        'running' => 'Taranıyor',
        'completed' => 'Tamamlandı',
        'failed' => 'Hata',
        'cancelled' => 'Durduruldu',
    ];

    protected $guarded = [];

    protected $hidden = ['session_cookie'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sitemap_found' => 'boolean',
            'bot_access' => 'array',
            'llms_txt' => 'array',
            'wp_checks' => 'array',
            'pagespeed' => 'array',
            'session_cookie' => 'encrypted',
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

    /**
     * How far a running crawl has got: pages done out of the pages found so far (capped by the URL limit) and the
     * current step, in the order CrawlRunner works through them.
     *
     * @return array{done: int, total: int, percent: int, phase: string}
     */
    public function progress(): array
    {
        $done = $this->pages()->where('state', '!=', 'queued')->count();
        $queued = $this->pages()->where('state', 'queued')->count();
        $total = max($done, min($done + $queued, (int) ($this->settings['max_urls'] ?? PHP_INT_MAX)));

        $phase = match (true) {
            $this->status === 'pending' => 'Sırada, birazdan başlayacak',
            $queued > 0 => 'Sayfalar taranıyor',
            $this->images()->where('state', 'queued')->exists() => 'Görseller kontrol ediliyor',
            DB::table('crawl_external_links')->where('crawl_id', $this->id)->where('state', 'queued')->exists() => 'Dış linkler kontrol ediliyor',
            collect($this->bot_access ?? [])->contains(fn ($bot) => $bot['fetch'] === 'pending') => 'Bot erişimi test ediliyor',
            collect($this->wp_checks['probes'] ?? [])->contains(fn ($probe) => $probe['state'] === 'pending') => 'WordPress kontrolleri yapılıyor',
            default => 'Sonuçlar hesaplanıyor',
        };

        return ['done' => $done, 'total' => $total, 'percent' => $total > 0 ? (int) floor($done / $total * 100) : 0, 'phase' => $phase];
    }

    public function activityLabel(): string
    {
        return 'Tarama #'.$this->id.' ('.($this->project?->name ?? '').')';
    }

    /** @return array<int, string> */
    public function activityOnly(): array
    {
        return ['status'];
    }
}
