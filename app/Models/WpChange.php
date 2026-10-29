<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to a WordPress page made through the Connector plugin (docs/07 WP-3): proposed in the panel, applied only
 * after someone approves it, and undone by a reverse change.
 */
class WpChange extends Model
{
    use LogsActivity;

    public const FIELDS = ['title' => 'SEO başlığı', 'description' => 'Meta açıklama', 'noindex' => 'Arama motoru dizini', 'alt' => 'Görsel alt metni', 'redirect' => '301 yönlendirme'];

    public const NOINDEX = ['' => 'Varsayılan (SEO eklentisinin ayarı)', 'index' => 'Dizine al (index)', 'noindex' => 'Dizine alma (noindex)'];

    public const STATUSES = ['proposed' => 'Onay bekliyor', 'applied' => 'Uygulandı', 'conflict' => 'Çakışma', 'failed' => 'Uygulanamadı', 'reverted' => 'Geri alındı', 'rejected' => 'Reddedildi'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function activityLabel(): string
    {
        return 'WordPress değişikliği ('.(self::FIELDS[$this->field] ?? $this->field).'): '.$this->url;
    }

    /** @return array<int, string> */
    public function activityOnly(): array
    {
        return ['status'];
    }

    public function show(?string $value): string
    {
        return match (true) {
            $this->field === 'noindex' => self::NOINDEX[(string) $value] ?? (string) $value,
            (string) $value !== '' => (string) $value,
            $this->field === 'redirect' => '(yönlendirme yok)',
            $this->field === 'alt' => '(boş)',
            default => '(boş, SEO eklentisi varsayılanı)',
        };
    }
}
