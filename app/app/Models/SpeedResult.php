<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeedResult extends Model
{
    public const STRATEGIES = ['mobile' => 'Mobil', 'desktop' => 'Masaüstü'];

    public const STATES = ['pending' => 'Sırada', 'running' => 'Ölçülüyor', 'done' => 'Tamam', 'failed' => 'Ölçülemedi', 'cancelled' => 'Durduruldu'];

    public const CWV = ['good' => 'İyi', 'needs' => 'İyileştirilmeli', 'poor' => 'Kötü'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cls' => 'float',
            'field_cls' => 'float',
            'cwv_from_field' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function crawl(): BelongsTo
    {
        return $this->belongsTo(Crawl::class);
    }
}
