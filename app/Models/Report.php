<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Report extends Model
{
    public const STATUSES = ['draft' => 'Onay bekliyor', 'approved' => 'Onaylandı', 'sent' => 'Müşteriye gönderildi'];

    /** Stands in the stored web HTML for the PDF link, which depends on the address the report is served from. */
    public const PDF_URL_PLACEHOLDER = '__SR_PDF_URL__';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function crawl(): BelongsTo
    {
        return $this->belongsTo(Crawl::class);
    }

    /** "Ekim 2026" */
    public function periodLabel(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->period)->locale('tr')->translatedFormat('F Y');
    }

    public function approve(User $by): void
    {
        $this->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'token' => $this->token ?? Str::random(40)]);
        if (! $this->project->archive_token) {
            $this->project->forceFill(['archive_token' => Str::random(40)])->save();
        }
    }

    public function url(): ?string
    {
        return $this->token ? route('monthly-report.show', $this->token) : null;
    }

    public function filename(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii($this->project->name))) ?: 'rapor';

        return 'seo-raporu-'.trim($slug, '-').'-'.$this->period.'.pdf';
    }
}
