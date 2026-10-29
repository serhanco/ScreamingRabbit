<?php

namespace App\Audit;

/**
 * Checks the home page response for the browser security headers that Mozilla Observatory and the OWASP
 * Secure Headers Project recommend for every site.
 */
class SecurityHeaders
{
    /** Six months: the shortest HSTS max-age Mozilla Observatory accepts. */
    public const HSTS_MIN_AGE = 15768000;

    /**
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<int, string> missing or weak headers, as shown to the user
     */
    public static function problems(array $headers, bool $https): array
    {
        $headers = array_change_key_case(array_map(fn ($value) => trim(implode(', ', (array) $value)), $headers));
        $csp = strtolower($headers['content-security-policy'] ?? '');
        $problems = [];

        if ($https) {
            $hsts = $headers['strict-transport-security'] ?? '';
            if ($hsts === '') {
                $problems[] = 'Strict-Transport-Security';
            } elseif (preg_match('/max-age\s*=\s*"?(\d+)/i', $hsts, $match) !== 1 || (int) $match[1] < self::HSTS_MIN_AGE) {
                $problems[] = 'Strict-Transport-Security (max-age 6 aydan kısa)';
            }
        }
        if ($csp === '') {
            $problems[] = 'Content-Security-Policy';
        }
        if (strtolower($headers['x-content-type-options'] ?? '') !== 'nosniff') {
            $problems[] = 'X-Content-Type-Options';
        }
        // CSP frame-ancestors replaces X-Frame-Options against clickjacking.
        if (($headers['x-frame-options'] ?? '') === '' && ! str_contains($csp, 'frame-ancestors')) {
            $problems[] = 'X-Frame-Options';
        }
        if (($headers['referrer-policy'] ?? '') === '') {
            $problems[] = 'Referrer-Policy';
        }

        return $problems;
    }
}
