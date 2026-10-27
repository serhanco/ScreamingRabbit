<?php

namespace App\Filament\Concerns;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * For resources whose rows belong to a project: members other than the primary admin only see rows of projects they
 * own or are assigned to, and viewers
 * cannot create, edit or delete (docs/09 Faz 4, roles).
 *
 * Resources set $projectColumn to the column holding the project id; the project resource itself uses 'id'.
 */
trait ScopedToUserProjects
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        if ($user === null || $user->isPrimary()) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn(property_exists(static::class, 'projectColumn') ? static::$projectColumn : 'project_id'), Project::query()->visibleTo($user)->select('projects.id'));
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canWrite() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->canWrite() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->canWrite() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->canWrite() ?? false;
    }
}
