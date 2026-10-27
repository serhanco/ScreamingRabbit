<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** A member's place on a project: which of Project::PERMISSIONS they have there (null = decided by their role). */
class ProjectMember extends Pivot
{
    protected $table = 'project_user';

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
