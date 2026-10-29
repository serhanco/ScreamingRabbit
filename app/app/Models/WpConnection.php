<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's link to the ScreamingRabbit Connector plugin on its WordPress site: the pairing code while it waits,
 * then the shared signing secret and the latest inventory the plugin reported.
 */
#[Fillable(['project_id', 'code_hash', 'code_expires_at', 'secret', 'site_url', 'plugin_version', 'connected_at', 'synced_at', 'inventory', 'sync_error'])]
#[Hidden(['code_hash', 'secret'])]
class WpConnection extends Model
{
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'code_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'synced_at' => 'datetime',
            'inventory' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isConnected(): bool
    {
        return $this->connected_at !== null && filled($this->secret);
    }
}
