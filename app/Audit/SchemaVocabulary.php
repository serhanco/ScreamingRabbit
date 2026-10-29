<?php

namespace App\Audit;

/**
 * Checks JSON-LD against the schema.org vocabulary (resources/schema/vocabulary.json, built from the official
 * schemaorg-current-https.jsonld, release 29.3): unknown types, unknown properties, properties that do not belong to the
 * node's type, and objects given where only text, a number, a date or a URL is allowed. Search engines ignore what
 * they cannot interpret, so these mistakes silently cost rich results.
 */
class SchemaVocabulary
{
    public const MAX_PROBLEMS = 5;

    /** @var array{types: array<string, array<int, string>>, props: array<string, array<int, string>>, textOnly: array<int, string>}|null */
    private static ?array $vocabulary = null;

    /** @var array<string, array<int, string>> */
    private static array $ancestors = [];

    /**
     * @param  array<int, mixed>  $blocks  decoded JSON-LD blocks
     * @return array<int, string>
     */
    public static function problems(array $blocks): array
    {
        $problems = [];
        foreach ($blocks as $block) {
            if (is_array($block) && self::usesSchemaOrg($block['@context'] ?? 'https://schema.org')) {
                self::walk($block, $problems);
            }
        }

        return array_slice(array_values(array_unique($problems)), 0, self::MAX_PROBLEMS);
    }

    /** @param  array<int, string>  $problems */
    private static function walk(mixed $node, array &$problems): void
    {
        if (! is_array($node)) {
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $item) {
                self::walk($item, $problems);
            }

            return;
        }

        $vocabulary = self::vocabulary();
        $types = [];
        foreach ((array) ($node['@type'] ?? []) as $type) {
            $name = is_string($type) ? self::local($type) : null;
            if ($name === null) {
                continue;
            }
            if (! isset($vocabulary['types'][$name])) {
                $problems[] = "Bilinmeyen tür: {$name}";
            } else {
                $types[] = $name;
            }
        }

        foreach ($node as $key => $value) {
            if (! is_string($key) || str_starts_with($key, '@')) {
                if ($key === '@graph') {
                    self::walk($value, $problems);
                }

                continue;
            }
            $property = self::local($key);
            if ($property !== null && $types !== []) {
                $domains = $vocabulary['props'][$property] ?? null;
                if ($domains === null) {
                    $problems[] = "Bilinmeyen özellik: {$types[0]}.{$property}";
                } elseif ($domains !== [] && array_intersect($domains, self::lineage($types)) === []) {
                    $problems[] = "{$types[0]} türünde geçersiz özellik: {$property}";
                } elseif (in_array($property, $vocabulary['textOnly'], true) && self::isTypedObject($value)) {
                    $problems[] = "Yanlış değer tipi: {$types[0]}.{$property} metin, sayı, tarih veya adres olmalı";
                }
            }
            if (is_array($value)) {
                self::walk($value, $problems);
            }
        }
    }

    /** "Product" for Product, schema:Product and https://schema.org/Product; null for other vocabularies. */
    private static function local(string $term): ?string
    {
        if (preg_match('#^(?:https?://schema\.org/|schema:)([A-Za-z0-9_]+)$#', $term, $match)) {
            return $match[1];
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $term) ? $term : null;
    }

    private static function usesSchemaOrg(mixed $context): bool
    {
        if (is_string($context)) {
            return str_contains($context, 'schema.org');
        }

        return is_array($context) && collect($context)->flatten()->contains(fn ($value) => is_string($value) && str_contains($value, 'schema.org'));
    }

    private static function isTypedObject(mixed $value): bool
    {
        if (is_array($value) && array_is_list($value)) {
            return collect($value)->contains(fn ($item) => self::isTypedObject($item));
        }

        return is_array($value) && isset($value['@type']);
    }

    /**
     * The types and all their parents (Dentist → MedicalBusiness, LocalBusiness, Organization, Place, Thing …).
     *
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    private static function lineage(array $types): array
    {
        $all = [];
        foreach ($types as $type) {
            if (! isset(self::$ancestors[$type])) {
                $seen = [];
                $queue = [$type];
                while ($queue !== []) {
                    $current = array_shift($queue);
                    if (isset($seen[$current])) {
                        continue;
                    }
                    $seen[$current] = true;
                    array_push($queue, ...(self::vocabulary()['types'][$current] ?? []));
                }
                self::$ancestors[$type] = array_keys($seen);
            }
            array_push($all, ...self::$ancestors[$type]);
        }

        return $all;
    }

    /** @return array{types: array<string, array<int, string>>, props: array<string, array<int, string>>, textOnly: array<int, string>} */
    private static function vocabulary(): array
    {
        return self::$vocabulary ??= json_decode((string) file_get_contents(resource_path('schema/vocabulary.json')), true);
    }
}
