<?php

namespace App\Audit;

/**
 * 64-bit SimHash fingerprints of page text (Charikar; the method Google described for near-duplicate web pages).
 * Similar texts get fingerprints that differ in few bits, so near duplicates are found by Hamming distance.
 */
class SimHash
{
    /** At most this many differing bits counts as a near duplicate (about 95% similar). */
    public const MAX_DISTANCE = 3;

    /** Pages with less main text than this are skipped: menus and footers alone make them look alike. */
    public const MIN_WORDS = 100;

    /**
     * @return ?string 16 hex characters, or null when the text is too short to compare
     */
    public static function of(string $text): ?string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $match);
        $words = $match[0];
        if (count($words) < self::MIN_WORDS) {
            return null;
        }

        $weights = array_fill(0, 64, 0);
        for ($i = 0, $last = count($words) - 3; $i <= $last; $i++) {
            $hash = unpack('J', md5($words[$i].' '.$words[$i + 1].' '.$words[$i + 2], true))[1];
            for ($bit = 0; $bit < 64; $bit++) {
                $weights[$bit] += (($hash >> $bit) & 1) ? 1 : -1;
            }
        }

        $fingerprint = 0;
        foreach ($weights as $bit => $weight) {
            if ($weight > 0) {
                $fingerprint |= 1 << $bit;
            }
        }

        return sprintf('%016x', $fingerprint);
    }

    public static function distance(string $a, string $b): int
    {
        $bits = 0;
        foreach ([0, 8] as $offset) {
            $bits += substr_count(decbin(hexdec(substr($a, $offset, 8)) ^ hexdec(substr($b, $offset, 8))), '1');
        }

        return $bits;
    }

    /**
     * Groups fingerprints that are within MAX_DISTANCE of each other. With 4 bands of 16 bits, two fingerprints
     * that differ in 3 bits or fewer share at least one whole band, so only pages in the same band bucket are compared.
     *
     * @param  array<int, string>  $fingerprints  id => fingerprint
     * @return array<int, array<int, int>> id => ids of its near duplicates
     */
    public static function nearDuplicates(array $fingerprints): array
    {
        $buckets = [];
        foreach ($fingerprints as $id => $fingerprint) {
            for ($band = 0; $band < 4; $band++) {
                $buckets[$band.substr($fingerprint, $band * 4, 4)][] = $id;
            }
        }

        $matches = [];
        foreach ($buckets as $ids) {
            $count = count($ids);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$a, $b] = [$ids[$i], $ids[$j]];
                    if (! isset($matches[$a][$b]) && self::distance($fingerprints[$a], $fingerprints[$b]) <= self::MAX_DISTANCE) {
                        $matches[$a][$b] = $b;
                        $matches[$b][$a] = $a;
                    }
                }
            }
        }

        return array_map('array_values', $matches);
    }
}
