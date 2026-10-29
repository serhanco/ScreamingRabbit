<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;

/**
 * Writes a line to the activity log when a signed-in member creates, changes or deletes the model.
 * A model may define activityLabel(), activityOnly() (the only columns worth logging) and activityHidden().
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn ($model) => ActivityLog::record('created', $model));
        static::updated(fn ($model) => ActivityLog::record('updated', $model));
        static::deleted(fn ($model) => ActivityLog::record('deleted', $model));
    }
}
