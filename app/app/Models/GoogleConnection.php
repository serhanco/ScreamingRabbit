<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's own Google account (offline access). Only that member sees the account's Search Console site list.
 */
#[Fillable(['user_id', 'email', 'refresh_token'])]
#[Hidden(['refresh_token'])]
class GoogleConnection extends Model
{
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'calls_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Counts today's API calls on this connection; the counters start again each day. */
    public function countCall(bool $inspection = false): void
    {
        if ($this->calls_on?->isToday() !== true) {
            $this->forceFill(['calls_on' => now()->toDateString(), 'calls' => 0, 'inspections' => 0]);
        }
        $this->calls++;
        if ($inspection) {
            $this->inspections++;
        }
        $this->save();
    }

    public function callsToday(): int
    {
        return $this->calls_on?->isToday() ? $this->calls : 0;
    }

    public function inspectionsToday(): int
    {
        return $this->calls_on?->isToday() ? $this->inspections : 0;
    }
}
