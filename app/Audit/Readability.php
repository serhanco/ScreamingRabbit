<?php

namespace App\Audit;

/**
 * Turkish readability with the Ateşman formula (1997), the Flesch adaptation for Turkish:
 * 198.825 − 40.175 × (syllables / words) − 2.610 × (words / sentences). In Turkish every syllable has exactly one
 * vowel, so syllables are counted as vowels.
 */
class Readability
{
    public const MIN_WORDS = 100;

    /** Scores under this are "zor" (hard) or worse. */
    public const HARD = 50;

    public static function atesman(string $text): ?int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $words = preg_match_all('/\p{L}+/u', $text, $matches);
        if ($words < self::MIN_WORDS) {
            return null;
        }

        $sentences = max(1, preg_match_all('/[^.!?…]*\p{L}[^.!?…]*[.!?…]+/u', $text.'.'));
        $syllables = preg_match_all('/[aeıioöuüâîûAEIİOÖUÜÂÎÛ]/u', implode('', $matches[0]));

        $score = 198.825 - 40.175 * ($syllables / $words) - 2.610 * ($words / $sentences);

        return (int) round(max(0, min(100, $score)));
    }

    public static function label(?int $score): string
    {
        return match (true) {
            $score === null => '-',
            $score >= 90 => 'Çok kolay',
            $score >= 70 => 'Kolay',
            $score >= self::HARD => 'Orta',
            $score >= 30 => 'Zor',
            default => 'Çok zor',
        };
    }

    /** Whether the page is Turkish: html lang="tr…", or no lang and letters only Turkish uses. */
    public static function isTurkish(?string $lang, string $text): bool
    {
        $lang = strtolower(trim((string) $lang));
        if ($lang !== '') {
            return str_starts_with($lang, 'tr');
        }

        return preg_match_all('/[ğışİĞŞ]/u', $text) >= 5;
    }
}
