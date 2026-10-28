<?php

namespace App\Models;

use App\Monitoring\Alerts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One alert in the notification center, whoever it was delivered to. */
class AlertEvent extends Model
{
    public const UPDATED_AT = null;

    public const SEVERITIES = ['critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük'];

    protected $guarded = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    public function kindLabel(): string
    {
        return Alerts::LABELS[$this->kind] ?? $this->kind;
    }
}
