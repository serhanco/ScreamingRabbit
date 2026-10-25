<?php

namespace App\Support;

/**
 * Where the instance's own files live (.env, storage/, install lock).
 *
 * On shared hosting the web root is replaced wholesale on every Git deployment, which would delete them,
 * so in that layout they live in a `screamingrabbit-data` folder next to the web root instead.
 * In a normal checkout this returns null and Laravel keeps using the project folder.
 */
class DataDir
{
    private const SUBDIRS = [
        'storage/app/public',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
    ];

    public static function resolve(string $basePath): ?string
    {
        $basePath = rtrim($basePath, '/\\');
        $webRoot = dirname($basePath);

        // Deploy layout only: <web root>/app next to <web root>/index.php.
        if (basename($basePath) !== 'app' || ! is_file($webRoot.'/index.php')) {
            return null;
        }

        $dir = dirname($webRoot).'/screamingrabbit-data';

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            return null;
        }

        foreach (self::SUBDIRS as $sub) {
            if (! is_dir($dir.'/'.$sub) && ! @mkdir($dir.'/'.$sub, 0750, true) && ! is_dir($dir.'/'.$sub)) {
                return null;
            }
        }

        return is_writable($dir) ? $dir : null;
    }
}
