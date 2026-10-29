<?php

namespace App\Crawler;

/**
 * Recognises addresses of a site's test copy (staging.example.com, dev.example.com) and of hosting platforms' staging
 * domains (example.wpengine.com, …kinsta.cloud). Links or canonicals pointing there leak the test site to search engines.
 */
class StagingHost
{
    private const PREFIX = '/^(staging|stage|stg|dev|develop|development|test|testing|demo|beta|preprod|pre-prod|uat|qa|sandbox)\d*$/';

    private const PLATFORMS = ['wpengine.com', 'wpenginepowered.com', 'kinsta.cloud', 'flywheelsites.com', 'pantheonsite.io',
        'hostingersite.com', 'instawp.xyz', 'cloudwaysapps.com', 'wpcomstaging.com', 'sg-host.com', 'wpstagecoach.com'];

    /** Second-level labels under which domains are registered, as in example.com.tr. */
    private const SECOND_LEVEL = ['com', 'net', 'org', 'gen', 'edu', 'gov', 'bel', 'web', 'biz', 'info', 'av', 'co', 'k12'];

    public static function is(?string $host, string $siteHost): bool
    {
        $host = strtolower(preg_replace('/^www\./i', '', (string) $host));
        $siteHost = strtolower(preg_replace('/^www\./i', '', $siteHost));
        if ($host === '' || $host === $siteHost) {
            return false;
        }

        foreach (self::PLATFORMS as $platform) {
            if (str_ends_with($host, '.'.$platform) && ! str_ends_with($siteHost, '.'.$platform)) {
                return true;
            }
        }

        $root = self::root($siteHost);

        return str_ends_with($host, '.'.$root) && preg_match(self::PREFIX, explode('.', $host)[0]) === 1;
    }

    private static function root(string $host): string
    {
        $labels = explode('.', $host);
        $count = count($labels) >= 3 && strlen(end($labels)) === 2 && in_array($labels[count($labels) - 2], self::SECOND_LEVEL, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$count));
    }
}
