<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlImage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function crawl(): BelongsTo
    {
        return $this->belongsTo(Crawl::class);
    }
}
