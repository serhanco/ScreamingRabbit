<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change made by a signed-in member. Background work (crawls, monitors, the scheduler) is not logged: it has
 * no member behind it and would drown the changes people made.
 */
class ActivityLog extends Model
{
    public const ACTIONS = ['created' => 'Ekledi', 'updated' => 'Değiştirdi', 'deleted' => 'Sildi'];

    /** Plain names for the columns people change most. */
    public const FIELDS = [
        'status' => 'Durum', 'name' => 'Ad', 'email' => 'E-posta', 'password' => 'Şifre', 'role' => 'Rol', 'is_active' => 'Hesap açık',
        'app_authentication_secret' => '2FA', 'note' => 'Not', 'due_on' => 'Son tarih', 'show_in_report' => 'Raporda', 'sent_to' => 'Gönderilen',
        'url' => 'Adres', 'interval_minutes' => 'Sıklık (dk)', 'keyword' => 'Anahtar kelime', 'base_url' => 'Site adresi',
        'gsc_property' => 'Search Console mülkü', 'crawl_schedule' => 'Otomatik tarama', 'crawl_settings' => 'Tarama ayarları',
        'platform' => 'Platform', 'client_id' => 'Müşteri', 'contact_email' => 'İletişim e-postası', 'notes' => 'Notlar', 'value' => 'Değer',
    ];

    /** Kept this long, then pruned. */
    public const RETENTION_DAYS = 365;

    /** Columns that change on their own and say nothing about what a person did. */
    private const IGNORED = ['created_at', 'updated_at', 'remember_token'];

    /** Values never written to the log, only the fact that they changed. */
    private const HIDDEN = ['password', 'app_authentication_secret', 'app_authentication_recovery_codes', 'token', 'share_token', 'archive_token', 'value'];

    protected $table = 'activity_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public static function record(string $action, Model $model): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $changes = null;
        if ($action === 'updated') {
            $changes = [];
            $only = method_exists($model, 'activityOnly') ? $model->activityOnly() : null;
            $hidden = array_merge(self::HIDDEN, method_exists($model, 'activityHidden') ? $model->activityHidden() : []);
            foreach ($model->getChanges() as $field => $new) {
                if (in_array($field, self::IGNORED, true) || str_starts_with($field, 'last_') || ($only !== null && ! in_array($field, $only, true))) {
                    continue;
                }
                $changes[$field] = in_array($field, $hidden, true)
                    ? ['old' => '••••', 'new' => '••••']
                    : ['old' => self::short($model->getOriginal($field)), 'new' => self::short($new)];
            }
            if ($changes === []) {
                return;
            }
        }

        static::create([
            'user_id' => $user->id,
            'project_id' => $model instanceof Project ? ($action === 'deleted' ? null : $model->id) : $model->getAttribute('project_id'),
            'action' => $action,
            'subject_type' => class_basename($model),
            'subject_id' => (string) $model->getKey(),
            'label' => mb_substr(method_exists($model, 'activityLabel') ? $model->activityLabel() : class_basename($model).' #'.$model->getKey(), 0, 250),
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    private static function short(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return is_string($value) ? mb_substr($value, 0, 200) : $value;
    }
}
