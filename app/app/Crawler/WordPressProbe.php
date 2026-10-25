<?php

namespace App\Crawler;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Passive WordPress checks from outside (docs/07 §2): a short, fixed list of GET/HEAD requests to well-known paths,
 * plus version lookups on api.wordpress.org. No logins, no form posts, no XML-RPC method calls.
 */
class WordPressProbe
{
    private const CONFIG_BACKUPS = ['wp-config.php.bak', 'wp-config.php.old', 'wp-config.php.save', 'wp-config.php~', 'wp-config.php.orig', 'wp-config.bak'];

    private const MAX_PLUGIN_LOOKUPS = 25;

    /**
     * Plugin and theme slugs with the asset version seen on the start page.
     *
     * @return array{plugins: array<string, ?string>, themes: array<string, ?string>}
     */
    public static function inventory(string $html): array
    {
        $found = ['plugins' => [], 'themes' => []];

        preg_match_all('#/wp-content/(plugins|themes)/([a-z0-9_.-]+)/[^"\'\s>]*#i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as [$path, $kind, $slug]) {
            $slug = strtolower($slug);
            $version = preg_match('/[?&]ver=([0-9][0-9a-z.\-]*)/i', html_entity_decode($path), $m) ? $m[1] : null;
            $found[$kind][$slug] = ($found[$kind][$slug] ?? null) ?? $version;
        }

        return $found;
    }

    /**
     * The probe list for a site, every item pending.
     *
     * @param  array{plugins: array<string, ?string>, themes: array<string, ?string>}  $inventory
     * @return array<string, array<string, mixed>>
     */
    public static function initial(array $inventory): array
    {
        $keys = ['readme', 'license', 'debug_log', 'uploads_index', 'plugins_index', 'xmlrpc', 'rest_users', 'author_redirect',
            'login_http', 'search', 'feed', 'core_latest', 'wp_sitemap', 'sitemap_index'];
        foreach (self::CONFIG_BACKUPS as $file) {
            $keys[] = 'backup:'.$file;
        }
        foreach (array_slice(array_keys($inventory['plugins']), 0, self::MAX_PLUGIN_LOOKUPS) as $slug) {
            $keys[] = 'plugin:'.$slug;
        }

        return array_fill_keys($keys, ['state' => 'pending']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $probes
     * @return array<string, array<string, mixed>>
     */
    public static function run(array $probes, array $keys, string $origin, string $userAgent, int $timeout): array
    {
        $responses = Http::pool(fn (Pool $pool) => array_map(function (string $key) use ($pool, $origin, $userAgent, $timeout) {
            [$method, $url] = self::request($key, $origin);
            $request = $pool->as($key)->withUserAgent($userAgent)->timeout($timeout)->withoutRedirecting();

            return $method === 'HEAD' ? $request->head($url) : $request->get($url);
        }, $keys));

        foreach ($keys as $key) {
            $response = $responses[$key] ?? null;
            $probes[$key] = ['state' => 'done'] + ($response instanceof Response ? self::evaluate($key, $response, $origin) : ['status' => null]);
        }

        return $probes;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function request(string $key, string $origin): array
    {
        if (str_starts_with($key, 'backup:')) {
            return ['HEAD', $origin.'/'.substr($key, 7)];
        }
        if (str_starts_with($key, 'plugin:')) {
            return ['GET', 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]='.rawurlencode(substr($key, 7)).'&request[fields][sections]=0'];
        }

        return match ($key) {
            'readme' => ['GET', $origin.'/readme.html'],
            'license' => ['GET', $origin.'/license.txt'],
            'debug_log' => ['HEAD', $origin.'/wp-content/debug.log'],
            'uploads_index' => ['GET', $origin.'/wp-content/uploads/'],
            'plugins_index' => ['GET', $origin.'/wp-content/plugins/'],
            'xmlrpc' => ['GET', $origin.'/xmlrpc.php'],
            'rest_users' => ['GET', $origin.'/wp-json/wp/v2/users'],
            'author_redirect' => ['GET', $origin.'/?author=1'],
            'login_http' => ['GET', 'http://'.parse_url($origin, PHP_URL_HOST).'/wp-login.php'],
            'search' => ['GET', $origin.'/?s=screamingrabbit'],
            'feed' => ['GET', $origin.'/feed/'],
            'core_latest' => ['GET', 'https://api.wordpress.org/core/version-check/1.7/'],
            'wp_sitemap' => ['HEAD', $origin.'/wp-sitemap.xml'],
            'sitemap_index' => ['HEAD', $origin.'/sitemap_index.xml'],
        };
    }

    /**
     * Turns a response into {status, finding (bool), value}. "finding" means the check found a problem.
     *
     * @return array<string, mixed>
     */
    private static function evaluate(string $key, Response $response, string $origin): array
    {
        $status = $response->status();
        $ok = $status === 200;
        $type = strtolower((string) $response->header('Content-Type'));
        $body = in_array($key, ['debug_log', 'wp_sitemap', 'sitemap_index'], true) || str_starts_with($key, 'backup:') ? '' : mb_strcut($response->body(), 0, 300_000);
        $result = ['status' => $status, 'finding' => false];

        if (str_starts_with($key, 'backup:')) {
            $result['finding'] = $ok && ! str_contains($type, 'html') && (int) $response->header('Content-Length') > 0;

            return $result;
        }
        if (str_starts_with($key, 'plugin:')) {
            $data = $response->json();
            $result['value'] = is_array($data) && isset($data['version']) ? (string) $data['version'] : null;

            return $result;
        }

        switch ($key) {
            case 'readme':
                $result['finding'] = $ok && stripos($body, 'wordpress') !== false;
                $result['value'] = preg_match('/Version\s+([0-9.]+)/i', $body, $m) ? $m[1] : null;
                break;
            case 'license':
                $result['finding'] = $ok && stripos($body, 'wordpress') !== false;
                break;
            case 'debug_log':
                $result['finding'] = $ok && ! str_contains($type, 'html') && (int) $response->header('Content-Length') !== 0;
                break;
            case 'uploads_index':
            case 'plugins_index':
                $result['finding'] = $ok && (stripos($body, '<title>Index of') !== false || stripos($body, '>Parent Directory<') !== false);
                break;
            case 'xmlrpc':
                $result['finding'] = stripos($body, 'XML-RPC server accepts POST requests only') !== false;
                break;
            case 'rest_users':
                $users = $ok ? $response->json() : null;
                $result['finding'] = is_array($users) && isset($users[0]['slug']);
                $result['value'] = $result['finding'] ? implode(', ', array_slice(array_column($users, 'slug'), 0, 5)) : null;
                break;
            case 'author_redirect':
                $location = (string) $response->header('Location');
                $result['finding'] = $status >= 300 && $status < 400 && (bool) preg_match('#/author/([^/]+)#', $location, $m);
                $result['value'] = $result['finding'] ? urldecode($m[1]) : null;
                break;
            case 'login_http':
                $location = (string) $response->header('Location');
                $result['finding'] = str_starts_with($origin, 'https://') && $ok && stripos($body, 'user_login') !== false;
                $result['value'] = $location ?: null;
                break;
            case 'search':
                $result['finding'] = $ok && ! preg_match('/<meta[^>]+name=["\']robots["\'][^>]+noindex/i', $body)
                    && ! str_contains(strtolower((string) $response->header('X-Robots-Tag')), 'noindex');
                break;
            case 'feed':
                $result['value'] = preg_match('#wordpress\.org/\?v=([0-9.]+)#i', $body, $m) ? $m[1] : null;
                break;
            case 'core_latest':
                $result['value'] = $response->json('offers.0.current');
                break;
            case 'wp_sitemap':
            case 'sitemap_index':
                $result['finding'] = $ok;
                break;
        }

        return $result;
    }
}
