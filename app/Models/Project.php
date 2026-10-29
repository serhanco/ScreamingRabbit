<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

#[Fillable(['client_id', 'name', 'base_url', 'platform', 'crawl_settings', 'crawl_auth', 'gsc_property', 'crawl_schedule'])]
class Project extends Model
{
    use HasFactory;
    use LogsActivity;

    public const PLATFORMS = [
        'wordpress' => 'WordPress',
        'generic' => 'Genel (diğer)',
    ];

    /** What an invited member may change on a project; seeing the project's data needs no permission. */
    public const PERMISSIONS = [
        'crawl' => 'Tarama başlatma ve durdurma',
        'tasks' => 'İş listesini düzenleme',
        'report' => 'Müşteri raporu bağlantısı paylaşma',
        'monitor' => 'Erişilebilirlik izlemelerini yönetme',
        'settings' => 'Proje ayarlarını düzenleme (Search Console mülkü hariç)',
    ];

    /** The usual set for a specialist working on a client site. */
    public const DEFAULT_PERMISSIONS = ['crawl', 'tasks', 'report'];

    protected function casts(): array
    {
        return [
            'crawl_settings' => 'array',
            'crawl_auth' => 'encrypted:array',
            'gsc_synced_at' => 'datetime',
            'gsc_queries_synced_on' => 'date',
        ];
    }

    /** @return array<int, string> */
    public function activityHidden(): array
    {
        return ['crawl_auth'];
    }

    /**
     * Basic auth and extra headers for crawling a protected (staging) site; sent only to the project's own host.
     *
     * @return array{user: ?string, password: ?string, headers: array<string, string>}
     */
    public function crawlAccess(): array
    {
        $auth = $this->crawl_auth ?? [];
        $headers = [];
        foreach ($auth['headers'] ?? [] as $header) {
            $name = trim((string) ($header['name'] ?? ''));
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) && ! in_array(strtolower($name), ['host', 'content-length', 'user-agent'], true)) {
                $headers[$name] = trim((string) ($header['value'] ?? ''));
            }
        }

        return ['user' => filled($auth['user'] ?? null) ? $auth['user'] : null, 'password' => $auth['password'] ?? null, 'headers' => $headers];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        // Every new site is watched for downtime from the start (docs/08 §2.1: default target is the home page).
        static::created(fn (Project $project) => $project->monitors()->create(['url' => $project->base_url]));

        static::creating(function (Project $project) {
            $project->owner_id ??= auth()->id();
        });

        // Whoever picks the Search Console property links it with their own Google connection.
        static::saving(function (Project $project) {
            if ($project->isDirty('gsc_property') && auth()->check()) {
                $project->gsc_user_id = filled($project->gsc_property) ? auth()->id() : null;
            }
        });
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /** Projects the user may see: all for the primary admin, the owned and assigned ones for everyone else. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null || $user->isPrimary()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('projects.id', DB::table('project_user')->where('user_id', $user->id)->select('project_id'))
            ->orWhere('projects.owner_id', $user->id));
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(ProjectMember::class)->withPivot('permissions');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The member whose Google connection reads this project's Search Console data. */
    public function gscUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gsc_user_id');
    }

    public function gscConnection(): ?GoogleConnection
    {
        return $this->gsc_user_id ? GoogleConnection::query()->where('user_id', $this->gsc_user_id)->first() : null;
    }

    public function wpConnection(): HasOne
    {
        return $this->hasOne(WpConnection::class);
    }

    public function crawls(): HasMany
    {
        return $this->hasMany(Crawl::class)->latest();
    }

    /**
     * Project crawl settings merged over the application defaults.
     *
     * @return array<string, mixed>
     */
    public function effectiveCrawlSettings(): array
    {
        // Blank form fields are stored as null/'' and must fall back to the default, while false stays a real value.
        $overrides = array_filter($this->crawl_settings ?? [], fn ($value) => $value !== null && $value !== '');

        return array_merge(config('screamingrabbit.crawl_defaults'), $overrides);
    }

    public function activityLabel(): string
    {
        return 'Proje: '.$this->name;
    }
}
