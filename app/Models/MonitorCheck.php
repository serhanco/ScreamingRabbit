<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorCheck extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'checked_at' => 'datetime'];
    }
}
