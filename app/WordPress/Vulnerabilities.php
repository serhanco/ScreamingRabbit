<?php

namespace App\WordPress;

use App\Models\WpConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Matches a Connector inventory (core, every installed plugin and theme) against the public WPVulnerability.net
 * database (https://www.wpvulnerability.net, no key needed). Answers are cached per component for a day, so sites
 * that share plugins cost one lookup.
 */
class Vulnerabilities
{
    public const SOURCE = 'https://www.wpvulnerability.net/';

    private const CACHE_HOURS = 24;

    private const TIMEOUT_SECONDS = 5;

    private const SEVERITIES = ['c' => 'Kritik', 'h' => 'Yüksek', 'm' => 'Orta', 'l' => 'Düşük', 'n' => 'Yok'];

    /**
     * Looks up every component of the connection's inventory and stores what affects the installed versions. Stops
     * at the first failed lookup and keeps the previous result, so an outage never clears known problems.
     */
    public static function check(WpConnection $connection): bool
    {
        $inventory = $connection->inventory;
        if (! is_array($inventory)) {
            return false;
        }

        $found = [];
        foreach (self::components($inventory) as $component) {
            $entries = self::lookup($component['type'], $component['slug']);
            if ($entries === null) {
                return false;
            }
            $items = self::affecting($entries, $component['version']);
            if ($items !== []) {
                $found[] = $component + ['items' => $items];
            }
        }

        $connection->update(['vulnerabilities' => $found, 'vulnerabilities_checked_at' => now()]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<int, array{type: string, slug: string, name: string, version: string}>
     */
    public static function components(array $inventory): array
    {
        $components = [];
        if (filled($inventory['core']['version'] ?? null)) {
            $components[] = ['type' => 'core', 'slug' => (string) $inventory['core']['version'], 'name' => 'WordPress', 'version' => (string) $inventory['core']['version']];
        }
        foreach ($inventory['plugins'] ?? [] as $plugin) {
            $file = (string) ($plugin['file'] ?? '');
            if (str_contains($file, '/') && filled($plugin['version'] ?? null)) {
                $components[] = ['type' => 'plugin', 'slug' => strtok($file, '/'), 'name' => (string) ($plugin['name'] ?? $file), 'version' => (string) $plugin['version']];
            }
        }
        foreach ($inventory['themes'] ?? [] as $theme) {
            if (filled($theme['slug'] ?? null) && filled($theme['version'] ?? null)) {
                $components[] = ['type' => 'theme', 'slug' => (string) $theme['slug'], 'name' => (string) ($theme['name'] ?? $theme['slug']), 'version' => (string) $theme['version']];
            }
        }

        return array_values(array_filter($components, fn (array $component) => preg_match('/^[A-Za-z0-9._-]+$/', $component['slug']) === 1));
    }

    /**
     * The database's entries for one component, [] when it has none, null when the lookup failed.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function lookup(string $type, string $slug): ?array
    {
        $key = "wpvuln:{$type}:{$slug}";
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->get(self::SOURCE."{$type}/{$slug}/");
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $entries = $response->json('data.vulnerability');
        $entries = is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
        Cache::put($key, $entries, now()->addHours(self::CACHE_HOURS));

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array{title: string, severity: ?string, score: ?float, fixed_in: ?string, unfixed: bool, link: ?string}>
     */
    public static function affecting(array $entries, string $version): array
    {
        $items = [];
        foreach ($entries as $entry) {
            $operator = is_array($entry['operator'] ?? null) ? $entry['operator'] : [];
            if (! self::applies($operator, $version)) {
                continue;
            }
            $severity = strtolower(substr((string) ($entry['impact']['cvss']['severity'] ?? ''), 0, 1));
            $score = $entry['impact']['cvss']['score'] ?? null;
            $items[] = [
                'title' => mb_substr(trim((string) ($entry['name'] ?? '')), 0, 200),
                'severity' => self::SEVERITIES[$severity] ?? null,
                'score' => is_numeric($score) ? (float) $score : null,
                'fixed_in' => in_array($operator['max_operator'] ?? null, ['lt'], true) ? (string) $operator['max_version'] : null,
                'unfixed' => (bool) ($entry['unfixed'] ?? $operator['unfixed'] ?? false),
                'link' => collect($entry['source'] ?? [])->pluck('link')->first(fn ($link) => is_string($link) && str_starts_with($link, 'https://')),
            ];
        }

        usort($items, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return $items;
    }

    /** @param  array<string, mixed>  $operator */
    private static function applies(array $operator, string $version): bool
    {
        $allowed = ['lt', 'le', 'gt', 'ge', 'eq', 'ne'];
        $checks = [];
        foreach (['min', 'max'] as $bound) {
            $op = $operator["{$bound}_operator"] ?? null;
            $at = $operator["{$bound}_version"] ?? null;
            if (in_array($op, $allowed, true) && filled($at)) {
                $checks[] = version_compare($version, (string) $at, $op);
            }
        }

        return $checks !== [] && ! in_array(false, $checks, true);
    }
}
