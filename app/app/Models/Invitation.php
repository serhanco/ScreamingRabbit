<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An e-mail invitation to one project. Someone new to the platform signs up through the link; an existing member
 * signs in and accepts. Either way they join the project with the permissions chosen by the inviting admin.
 */
#[Fillable(['email', 'project_id', 'permissions', 'token_hash', 'invited_by', 'expires_at'])]
class Invitation extends Model
{
    public const VALID_DAYS = 7;

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: Invitation, 1: string} the invitation and the plain token for the link
     */
    public static function issue(Project $project, string $email, array $permissions, User $inviter): array
    {
        $token = Str::random(48);
        $invitation = static::create([
            'email' => Str::lower(trim($email)),
            'project_id' => $project->id,
            'permissions' => array_values(array_intersect(array_keys(Project::PERMISSIONS), $permissions)),
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        return [$invitation, $token];
    }

    /** An open (not used, not expired) invitation for this token. */
    public static function findOpen(string $token): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function existingUser(): ?User
    {
        return User::query()->where('email', $this->email)->first();
    }

    /** Adds the user to the project with the invited permissions and closes the invitation. */
    public function accept(User $user): void
    {
        DB::transaction(function () use ($user) {
            if ($user->role === 'viewer' && $this->permissions !== []) {
                // Viewers cannot change anything anywhere; keep their other projects read-only and lift the cap.
                DB::table('project_user')->where('user_id', $user->id)->whereNull('permissions')->update(['permissions' => '[]']);
                $user->forceFill(['role' => 'specialist'])->save();
            }
            $user->projects()->syncWithoutDetaching([$this->project_id => ['permissions' => $this->permissions]]);
            $this->forceFill(['accepted_at' => now()])->save();
        });
    }
}
