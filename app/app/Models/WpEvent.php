<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A content change the Connector plugin reported (published, updated, unpublished) and the result of re-checking
 * that URL a minute later.
 */
class WpEvent extends Model
{
    public const EVENTS = ['published' => 'Yayınlandı', 'updated' => 'Güncellendi', 'unpublished' => 'Yayından kaldırıldı'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'checked_at' => 'datetime',
            'state' => 'array',
            'problems' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
