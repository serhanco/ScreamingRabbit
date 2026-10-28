<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDelivery extends Model
{
    public const UPDATED_AT = null;

    public const STATUSES = ['sent' => 'Gönderildi', 'held' => 'Sessiz saatte bekliyor', 'digest' => 'Haftalık özete', 'suppressed' => 'Tekrar, gönderilmedi'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['channels' => 'array', 'sent_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AlertEvent::class, 'alert_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
