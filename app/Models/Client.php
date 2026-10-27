<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'contact_email', 'notes'])]
class Client extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->owner_id ??= auth()->id();
        });
    }

    /** Clients the user may see: all for the primary admin, otherwise the owned ones and those with a visible project. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null || $user->isPrimary()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('clients.owner_id', $user->id)
            ->orWhereHas('projects', fn (Builder $projects) => $projects->visibleTo($user)));
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
