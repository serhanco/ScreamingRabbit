<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'alert_email', 'telegram_chat_id', 'alert_kinds', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = [
        'admin' => 'Yönetici (her şey)',
        'specialist' => 'Uzman (atandığı projeleri yönetir)',
        'viewer' => 'Salt okunur (atandığı projeleri görür)',
    ];

    protected $attributes = ['role' => 'admin', 'is_active' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'alert_kinds' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // Registration is closed; accounts are created by an admin in the panel (or with `php artisan make:filament-user`).
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Viewers read; admins and specialists change things. */
    public function canWrite(): bool
    {
        return $this->role !== 'viewer';
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    /** Whether this user may see the project: admins all of them, others only the ones assigned to them. */
    public function canSeeProject(?int $projectId): bool
    {
        return $this->isAdmin() || $projectId === null || $this->projects()->whereKey($projectId)->exists();
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    /** Users who never chose get every kind of alert. */
    public function wantsAlert(string $kind): bool
    {
        return $this->alert_kinds === null || in_array($kind, $this->alert_kinds, true);
    }
}
