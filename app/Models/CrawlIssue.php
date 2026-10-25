<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlIssue extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function crawl(): BelongsTo
    {
        return $this->belongsTo(Crawl::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(CrawlPage::class, 'page_id');
    }
}
