<?php

namespace App\Crawler;

use App\Models\Crawl;
use DOMDocument;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Project-defined searches run on every page's HTML source during the crawl: text that should or should not be
 * there (a tracking code, an old phone number), or a value to pull out with a regex or XPath (an author, a price).
 */
class CustomExtraction
{
    public const MAX_RULES = 5;

    public const TYPES = [
        'contains' => 'Kaynakta geçiyor mu (sayar)',
        'not_contains' => 'Kaynakta geçmeyen sayfalar',
        'regex' => 'Regex ile çıkar (ilk eşleşme)',
        'xpath' => 'XPath ile çıkar (ilk değer)',
        'css' => 'CSS seçici ile çıkar (ilk değer)',
    ];

    private const MAX_VALUE = 200;

    /**
     * @param  array<int, array{name?: ?string, type?: ?string, value?: ?string}>  $rules
     * @return array<string, int|bool|string|null> rule name => count (contains), missing (not_contains) or value
     */
    public static function apply(array $rules, string $html): array
    {
        $results = [];
        $xpath = null;
        foreach (self::valid($rules) as $rule) {
            $results[$rule['name']] = match ($rule['type']) {
                'contains' => mb_substr_count(mb_strtolower($html), mb_strtolower($rule['value'])),
                'not_contains' => mb_stripos($html, $rule['value']) === false,
                'regex' => self::regex($rule['value'], $html),
                'xpath' => self::xpath($xpath ??= self::dom($html), $rule['value']),
                'css' => ($query = self::cssToXpath($rule['value'])) === null ? null : self::xpath($xpath ??= self::dom($html), $query),
            };
        }

        return $results;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array{name: string, type: string, value: string}>
     */
    public static function valid(array $rules): array
    {
        return array_slice(array_values(array_filter($rules, fn ($rule) => filled($rule['name'] ?? null) && isset(self::TYPES[$rule['type'] ?? '']) && filled($rule['value'] ?? null))), 0, self::MAX_RULES);
    }

    public static function pattern(string $value): string
    {
        return '~'.str_replace('~', '\~', $value).'~iu';
    }

    public static function isValidXpath(string $value): bool
    {
        $xpath = new DOMXPath(new DOMDocument);
        $previous = libxml_use_internal_errors(true);
        $ok = @$xpath->evaluate($value) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok;
    }

    /** XPath for a CSS selector such as ".author-name" or "div.price > span"; null when the selector is invalid. */
    public static function cssToXpath(string $selector): ?string
    {
        try {
            return (new CssSelectorConverter)->toXPath($selector);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Per rule: how many pages matched and a few example URLs or the most common extracted values.
     *
     * @return array<int, array{name: string, type: string, value: string, pages: int, examples: array<int, string>}>
     */
    public static function summary(Crawl $crawl): array
    {
        $rules = self::valid($crawl->settings['custom'] ?? []);
        if ($rules === []) {
            return [];
        }

        $hits = array_fill_keys(array_column($rules, 'name'), ['pages' => 0, 'examples' => []]);
        foreach ($crawl->pages()->whereNotNull('custom')->select('id', 'url', 'custom')->cursor() as $page) {
            foreach ($rules as $rule) {
                $result = $page->custom[$rule['name']] ?? null;
                if ($result === null || $result === false || $result === 0) {
                    continue;
                }
                $hits[$rule['name']]['pages']++;
                $key = in_array($rule['type'], ['contains', 'not_contains'], true) ? $page->url : (string) $result;
                $hits[$rule['name']]['examples'][$key] = ($hits[$rule['name']]['examples'][$key] ?? 0) + 1;
            }
        }

        return array_map(function (array $rule) use ($hits) {
            $examples = $hits[$rule['name']]['examples'];
            arsort($examples);
            $examples = array_slice($examples, 0, 10, true);

            return $rule + [
                'pages' => $hits[$rule['name']]['pages'],
                'examples' => in_array($rule['type'], ['contains', 'not_contains'], true)
                    ? array_keys($examples)
                    : array_map(fn ($value, $count) => $count > 1 ? "{$value} ({$count} sayfa)" : (string) $value, array_keys($examples), $examples),
            ];
        }, $rules);
    }

    private static function regex(string $value, string $html): ?string
    {
        if (@preg_match(self::pattern($value), $html, $match) !== 1) {
            return null;
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $match[1] ?? $match[0])), 0, self::MAX_VALUE);
    }

    private static function xpath(DOMXPath $xpath, string $query): ?string
    {
        $previous = libxml_use_internal_errors(true);
        $result = @$xpath->evaluate($query);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $value = match (true) {
            $result instanceof \DOMNodeList => $result->length > 0 ? $result->item(0)->textContent : null,
            is_string($result) || is_numeric($result) => (string) $result,
            default => null,
        };
        $value = $value === null ? null : trim(preg_replace('/\s+/u', ' ', $value));

        return $value === null || $value === '' ? null : mb_substr($value, 0, self::MAX_VALUE);
    }

    private static function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
