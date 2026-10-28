<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchedPageChange extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['detected_at' => 'datetime'];
    }

    public function watchedPage(): BelongsTo
    {
        return $this->belongsTo(WatchedPage::class);
    }
}
