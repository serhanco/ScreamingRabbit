<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monitor extends Model
{
    use LogsActivity;

    public const INTERVALS = [1 => 'Her dakika', 5 => '5 dakikada bir', 15 => '15 dakikada bir'];

    public const STATUSES = ['unknown' => 'Bekliyor', 'up' => 'Çalışıyor', 'down' => 'Erişilemiyor'];

    public const CAUSES = [
        'dns' => 'Alan adı çözülemedi (DNS)',
        'timeout' => 'Zaman aşımı',
        'connect' => 'Bağlantı kurulamadı',
        'ssl' => 'SSL sertifikası hatası',
        'http' => 'Hata kodu döndü',
        'keyword' => 'Beklenen kelime sayfada yok',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class)->latest('started_at');
    }

    public function openIncident(): ?Incident
    {
        return $this->hasMany(Incident::class)->whereNull('ended_at')->latest('started_at')->first();
    }

    /** Share of successful checks in the last given hours, or null without data. */
    public function uptime(int $hours = 24): ?float
    {
        $row = $this->checks()->where('checked_at', '>=', now()->subHours($hours))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN ok THEN 1 ELSE 0 END) as up')->first();

        return $row && $row->total > 0 ? round($row->up / $row->total * 100, 2) : null;
    }

    public function activityLabel(): string
    {
        return 'İzleme: '.$this->url;
    }

    /** @return array<int, string> */
    public function activityOnly(): array
    {
        return ['url', 'interval_minutes', 'keyword', 'is_active'];
    }
}
