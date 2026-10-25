<?php

namespace App\Crawler;

/**
 * Include/exclude rules of a project: one regular expression per line, matched anywhere in the full URL.
 * A URL is crawled when it matches no exclude rule and, if include rules exist, at least one of them.
 */
class UrlFilter
{
    /** @var list<string> */
    private array $include;

    /** @var list<string> */
    private array $exclude;

    public function __construct(?string $include, ?string $exclude)
    {
        $this->include = self::compile($include);
        $this->exclude = self::compile($exclude);
    }

    public function allows(string $url): bool
    {
        foreach ($this->exclude as $pattern) {
            if (preg_match($pattern, $url)) {
                return false;
            }
        }

        if ($this->include === []) {
            return true;
        }

        foreach ($this->include as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the first line that is not a valid regular expression, or null when all are valid.
     */
    public static function firstInvalid(?string $lines): ?string
    {
        foreach (self::lines($lines) as $line) {
            if (@preg_match(self::wrap($line), '') === false) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function compile(?string $lines): array
    {
        return array_values(array_filter(
            array_map(self::wrap(...), self::lines($lines)),
            fn (string $pattern) => @preg_match($pattern, '') !== false,
        ));
    }

    /**
     * @return list<string>
     */
    private static function lines(?string $lines): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $lines)), fn ($l) => $l !== ''));
    }

    private static function wrap(string $line): string
    {
        return '#'.str_replace('#', '\#', $line).'#iu';
    }
}
