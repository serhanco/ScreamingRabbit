<?php

namespace App\WordPress;

use App\Models\Project;
use App\Models\WpConnection;
use App\Models\WpEvent;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talks to the ScreamingRabbit Connector plugin (docs/07 §3.3). Pairing: the panel makes a one-time code, the plugin
 * sends it back once from the site and receives the shared secret. After that the app calls the plugin's REST routes
 * with HMAC-signed requests; the plugin is read-only.
 */
class Connector
{
    public const CODE_MINUTES = 30;

    /** Inventory older than this is fetched again. */
    public const SYNC_HOURS = 24;

    private const TIMEOUT_SECONDS = 20;

    private const PER_RUN = 3;

    /** Page cache plugins by their folder name. */
    private const CACHE_PLUGINS = ['litespeed-cache', 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'sg-cachepress', 'breeze',
        'cache-enabler', 'wp-optimize', 'hummingbird-performance', 'comet-cache', 'swift-performance-lite', 'nitropack', 'flying-press', 'wp-cloudflare-page-cache'];

    /**
     * A new pairing code for the project, as the single string the plugin's settings page takes:
     * "SR1." + base64url JSON {"u": this app's address, "c": the code}. Any earlier code stops working.
     */
    public static function newCode(Project $project): string
    {
        $code = Str::random(32);
        WpConnection::updateOrCreate(['project_id' => $project->id], [
            'code_hash' => hash('sha256', $code),
            'code_expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);

        return 'SR1.'.rtrim(strtr(base64_encode(json_encode(['u' => rtrim(config('app.url'), '/'), 'c' => $code])), '+/', '-_'), '=');
    }

    /**
     * Completes pairing from the plugin's call: a valid, unexpired code from the project's own site.
     *
     * @return array{secret?: string, error?: string}
     */
    public static function pair(string $code, string $siteUrl, ?string $pluginVersion): array
    {
        $connection = WpConnection::where('code_hash', hash('sha256', $code))->where('code_expires_at', '>', now())->first();
        if ($connection === null) {
            return ['error' => 'Bağlantı kodu geçersiz veya süresi dolmuş. Panelden yeni kod alın.'];
        }
        if (! self::sameSite($siteUrl, $connection->project->base_url)) {
            return ['error' => 'Bu kod '.parse_url($connection->project->base_url, PHP_URL_HOST).' sitesi için. Kodu o sitenin WordPress paneline yapıştırın.'];
        }

        $secret = Str::random(64);
        $connection->update([
            'code_hash' => null,
            'code_expires_at' => null,
            'secret' => $secret,
            'site_url' => mb_substr($siteUrl, 0, 2048),
            'plugin_version' => mb_substr((string) $pluginVersion, 0, 20) ?: null,
            'connected_at' => now(),
            'synced_at' => null,
            'sync_error' => null,
        ]);

        return ['secret' => $secret];
    }

    public static function disconnect(Project $project): void
    {
        $project->wpConnection()->delete();
    }

    /**
     * Fetches the inventory and stores it, or the reason it failed. Returns whether it worked.
     */
    public static function sync(WpConnection $connection): bool
    {
        try {
            $response = self::get($connection, '/screamingrabbit/v1/inventory');
        } catch (\Throwable $e) {
            $connection->update(['synced_at' => now(), 'sync_error' => mb_substr('Siteye ulaşılamadı: '.$e->getMessage(), 0, 500)]);

            return false;
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! isset($data['core'])) {
            $connection->update(['synced_at' => now(), 'sync_error' => self::explain($response->status(), is_array($data) ? ($data['message'] ?? null) : null)]);

            return false;
        }

        $connection->update([
            'inventory' => $data,
            'plugin_version' => mb_substr((string) ($data['plugin_version'] ?? $connection->plugin_version), 0, 20),
            'synced_at' => now(),
            'sync_error' => null,
        ]);
        Vulnerabilities::check($connection);

        return true;
    }

    /** Refreshes a few connections whose inventory is a day old; called from the scheduler. */
    public static function runDue(): int
    {
        $due = WpConnection::query()->whereNotNull('connected_at')
            ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', now()->subHours(self::SYNC_HOURS)))
            ->orderBy('synced_at')->limit(self::PER_RUN)->get();

        return $due->filter(fn (WpConnection $connection) => self::sync($connection))->count();
    }

    /**
     * Signed GET to a plugin route. The ?rest_route= form works with and without pretty permalinks.
     */
    public static function get(WpConnection $connection, string $route): Response
    {
        $timestamp = (string) time();
        $nonce = Str::random(32);

        return Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->withHeaders([
            'X-SR-Timestamp' => $timestamp,
            'X-SR-Nonce' => $nonce,
            'X-SR-Signature' => self::sign((string) $connection->secret, 'GET', $route, $timestamp, $nonce, ''),
        ])->get(self::base($connection).'/', ['rest_route' => $route]);
    }

    /**
     * Names of the active page cache plugins in an inventory.
     *
     * @param  array<string, mixed>  $inventory
     * @return array<int, string>
     */
    public static function cachePlugins(array $inventory): array
    {
        return collect($inventory['plugins'] ?? [])
            ->filter(fn (array $plugin) => ($plugin['active'] ?? false) && in_array(strtok((string) ($plugin['file'] ?? ''), '/'), self::CACHE_PLUGINS, true))
            ->pluck('name')->values()->all();
    }

    /**
     * Stores a content change the plugin reported, after checking its signature (same scheme as the app's calls to
     * the plugin, nonces single-use for 10 minutes). Returns an error message, or null when it was accepted.
     *
     * @param  array<string, string>  $headers  site, timestamp, nonce, signature
     */
    public static function receiveEvent(array $headers, string $body): ?string
    {
        $host = strtolower($headers['site']);
        $timestamp = $headers['timestamp'];
        if ($host === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || preg_match('/^[A-Za-z0-9]{16,64}$/', $headers['nonce']) !== 1) {
            return 'stale';
        }
        $connection = WpConnection::query()->whereNotNull('connected_at')->whereNotNull('site_url')->get()
            ->filter(fn (WpConnection $connection) => self::sameSite('https://'.$host, (string) $connection->site_url) && filled($connection->secret))
            ->first(fn (WpConnection $connection) => hash_equals(self::sign((string) $connection->secret, 'POST', '/wp-baglanti/olay', $timestamp, $headers['nonce'], $body), $headers['signature']));
        if ($connection === null) {
            return 'bad_signature';
        }
        if (! Cache::add('wp-event-nonce:'.$headers['nonce'], 1, 600)) {
            return 'replayed';
        }

        $data = json_decode($body, true);
        $url = is_array($data) ? (string) ($data['url'] ?? '') : '';
        if (! isset(WpEvent::EVENTS[$data['event'] ?? '']) || mb_strlen($url) > 2048 || ! self::sameSite($url, $connection->project->base_url)) {
            return 'bad_payload';
        }

        WpEvent::create([
            'project_id' => $connection->project_id,
            'event' => $data['event'],
            'url' => $url,
            'post_type' => mb_substr((string) ($data['post_type'] ?? ''), 0, 40) ?: null,
            'received_at' => now(),
        ]);

        return null;
    }

    /** Version in the header of the plugin copy in this repository, the one the app hands out. */
    public static function pluginVersion(): string
    {
        $header = (string) file_get_contents(base_path('wordpress-plugin/screamingrabbit-connector/screamingrabbit-connector.php'), length: 2048);

        return preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $match) === 1 ? $match[1] : '0.0.0';
    }

    /**
     * Whether a plugin update download is from a paired site: the token is the plugin's HMAC of the version and its
     * host, made with that site's secret (SR_Updater::package_url()).
     */
    public static function packageAllowed(string $version, string $host, string $token): bool
    {
        if ($version !== self::pluginVersion() || $host === '' || $token === '') {
            return false;
        }

        return WpConnection::query()->whereNotNull('connected_at')->whereNotNull('site_url')->get()
            ->filter(fn (WpConnection $connection) => self::sameSite('https://'.$host, (string) $connection->site_url) && filled($connection->secret))
            ->contains(fn (WpConnection $connection) => hash_equals(hash_hmac('sha256', "package\n{$version}\n{$host}", (string) $connection->secret), $token));
    }

    /**
     * Signed POST with a JSON body to a plugin route.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function post(WpConnection $connection, string $route, array $payload): Response
    {
        $timestamp = (string) time();
        $nonce = Str::random(32);
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->withHeaders([
            'X-SR-Timestamp' => $timestamp,
            'X-SR-Nonce' => $nonce,
            'X-SR-Signature' => self::sign((string) $connection->secret, 'POST', $route, $timestamp, $nonce, $body),
        ])->withBody($body, 'application/json')->post(self::base($connection).'/?rest_route='.rawurlencode($route));
    }

    /** Same algorithm as the plugin's SR_Signature::sign(). */
    public static function sign(string $secret, string $method, string $route, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [strtoupper($method), $route, $timestamp, $nonce, hash('sha256', $body)]), $secret);
    }

    private static function base(WpConnection $connection): string
    {
        return rtrim($connection->site_url ?: $connection->project->base_url, '/');
    }

    private static function sameSite(string $siteUrl, string $baseUrl): bool
    {
        $host = fn (string $url) => preg_replace('/^www\./i', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

        return preg_match('#^https?://#i', $siteUrl) === 1 && $host($siteUrl) !== '' && $host($siteUrl) === $host($baseUrl);
    }

    private static function explain(int $status, ?string $message): string
    {
        return match (true) {
            $status === 401 => 'Eklenti isteği reddetti ('.($message ?: 'imza').'). Eklentide bağlantı kesilmiş olabilir; yeniden bağlayın.',
            $status === 404 => 'Eklenti bulunamadı. Eklenti silinmiş veya devre dışı olabilir.',
            $status === 429 => 'Eklenti kısa süre için istekleri sınırladı; sonra yeniden denenecek.',
            $status >= 500 => "Site hata verdi (HTTP {$status}).",
            default => "Beklenmeyen yanıt (HTTP {$status}).",
        };
    }
}
