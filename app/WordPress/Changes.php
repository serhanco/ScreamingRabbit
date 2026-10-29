<?php

namespace App\WordPress;

use App\Models\Project;
use App\Models\User;
use App\Models\WpChange;
use App\Models\WpConnection;

/**
 * Approved changes through the Connector plugin (docs/07 WP-3): SEO title, meta description and noindex of a page,
 * an image's alt text and 301 redirects.
 * A change is proposed with the value it replaces read from the site, applied only when someone approves it, and
 * refused by the plugin when the site's value changed in between. Undo is a reverse change with the same checks.
 */
class Changes
{
    private const ERRORS = [
        'sr_writes_disabled' => 'Sitede değişiklik izni kapalı: WordPress panelinde Ayarlar → ScreamingRabbit → "Değişiklik izni"ni açın.',
        'sr_not_a_post' => 'Bu adreste yayında bir yazı veya sayfa yok (kategori, arşiv ve ürün listesi sayfaları değiştirilemez).',
        'sr_not_this_site' => 'Adres bu siteye ait değil.',
        'sr_no_seo_plugin' => 'Sitede Yoast SEO veya Rank Math etkin değil; başlık ve açıklama yazılacak yer yok.',
        'sr_conflict' => 'Değer bu arada sitede değişmiş; güncel değerle yeniden önerin.',
        'sr_unauthorized' => 'Eklenti isteği reddetti; bağlantıyı yenileyin.',
        'sr_not_an_image' => 'Bu adreste medya kütüphanesinde bir görsel yok.',
        'sr_bad_target' => 'Yönlendirme aynı sitede başka bir adrese gitmeli.',
        'sr_too_many_redirects' => 'Eklentinin yönlendirme sınırı (500) doldu.',
        'sr_bad_request' => 'Sitedeki eklenti bu değişikliği tanımıyor; eklentiyi güncelleyin.',
        'rest_no_route' => 'Sitedeki eklenti eski; WordPress panelinden güncelleyin.',
    ];

    /**
     * Reads the page's current values from the site.
     *
     * @return array{values?: array<string, mixed>, error?: string}
     */
    public static function current(WpConnection $connection, string $url): array
    {
        try {
            $response = Connector::post($connection, '/screamingrabbit/v1/meta', ['url' => $url]);
        } catch (\Throwable $e) {
            return ['error' => 'Siteye ulaşılamadı: '.$e->getMessage()];
        }

        return $response->successful() && is_array($response->json())
            ? ['values' => $response->json()]
            : ['error' => self::explain($response->json('code'), $response->status())];
    }

    /**
     * @return WpChange|string the proposed change, or why it could not be proposed
     */
    public static function propose(Project $project, string $url, string $field, ?string $after, User $user, ?string $source = null, ?string $note = null): WpChange|string
    {
        $connection = $project->wpConnection;
        if (! isset(WpChange::FIELDS[$field]) || $connection === null || ! $connection->isConnected()) {
            return 'Proje WordPress eklentisine bağlı değil.';
        }
        $current = self::current($connection, $url);
        if (isset($current['error'])) {
            return $current['error'];
        }
        $values = $current['values'];
        if (! array_key_exists($field, $values)) {
            return $field === 'alt' ? self::ERRORS['sr_not_an_image'] : ($field === 'redirect' ? self::ERRORS['sr_bad_request'] : self::ERRORS['sr_not_a_post']);
        }
        $after = trim((string) $after);
        if ($field === 'redirect' && $after !== '' && (! Connector::sameSite($after, $project->base_url) || rtrim($after, '/') === rtrim($url, '/'))) {
            return self::ERRORS['sr_bad_target'];
        }
        $before = (string) $values[$field];
        if ($after === $before) {
            return 'Yeni değer sitedekiyle aynı.';
        }

        return WpChange::create([
            'project_id' => $project->id, 'url' => $url, 'post_id' => $values['post_id'] ?? $values['attachment_id'] ?? null, 'field' => $field,
            'before' => $before, 'after' => $after, 'status' => 'proposed', 'proposed_by' => $user->id,
            'source' => $source, 'note' => $note === null ? null : mb_substr($note, 0, 500),
        ]);
    }

    /** Sends an approved change to the site; the change ends applied, conflict or failed. */
    public static function apply(WpChange $change, User $approver): WpChange
    {
        if ($change->status !== 'proposed') {
            return $change;
        }
        $connection = $change->project->wpConnection;
        if ($connection === null || ! $connection->isConnected()) {
            $change->update(['status' => 'failed', 'error' => 'Proje WordPress eklentisine bağlı değil.', 'approved_by' => $approver->id]);

            return $change;
        }

        try {
            $response = Connector::post($connection, '/screamingrabbit/v1/change', [
                'change_id' => $change->id, 'url' => $change->url, 'field' => $change->field, 'before' => (string) $change->before, 'after' => (string) $change->after,
            ]);
        } catch (\Throwable $e) {
            $change->update(['status' => 'failed', 'error' => mb_substr('Siteye ulaşılamadı: '.$e->getMessage(), 0, 500), 'approved_by' => $approver->id]);

            return $change;
        }

        if ($response->successful()) {
            $change->update(['status' => 'applied', 'after' => (string) $response->json('after', $change->after), 'error' => null, 'approved_by' => $approver->id, 'applied_at' => now()]);
            if ($change->reverts_id !== null) {
                WpChange::whereKey($change->reverts_id)->update(['status' => 'reverted']);
            }
        } else {
            $code = $response->json('code');
            $change->update([
                'status' => $code === 'sr_conflict' ? 'conflict' : 'failed',
                'error' => mb_substr(self::explain($code, $response->status()).($code === 'sr_conflict' ? ' Sitedeki değer: "'.$response->json('data.current').'"' : ''), 0, 500),
                'approved_by' => $approver->id,
            ]);
        }

        return $change;
    }

    /** Undoes an applied change by applying its reverse at once; the original is marked reverted when that works. */
    public static function revert(WpChange $change, User $user): WpChange
    {
        $reverse = WpChange::create([
            'project_id' => $change->project_id, 'url' => $change->url, 'post_id' => $change->post_id, 'field' => $change->field,
            'before' => $change->after, 'after' => $change->before, 'status' => 'proposed', 'reverts_id' => $change->id, 'proposed_by' => $user->id,
        ]);

        return self::apply($reverse, $user);
    }

    private static function explain(mixed $code, int $status): string
    {
        return self::ERRORS[$code] ?? "Eklenti hata verdi (HTTP {$status}).";
    }
}
