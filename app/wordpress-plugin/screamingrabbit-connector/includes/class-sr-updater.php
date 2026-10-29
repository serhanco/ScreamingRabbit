<?php

/**
 * Offers new plugin versions on WordPress's normal Plugins / Updates screens. The version comes from the paired
 * ScreamingRabbit app; the download link carries an HMAC of the version made with this site's secret, so only paired
 * sites can fetch the package. Nothing is installed without an administrator pressing "Update" (or opting into
 * auto-updates for this plugin).
 */
defined('ABSPATH') || exit;

class SR_Updater
{
    const SLUG = 'screamingrabbit-connector';

    const CHECK_HOURS = 12;

    public static function register()
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'offer']);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
    }

    public static function offer($transient)
    {
        if (! is_object($transient)) {
            return $transient;
        }
        $latest = self::latest();
        $basename = self::SLUG.'/'.self::SLUG.'.php';
        if ($latest === null || version_compare($latest, SR_CONNECTOR_VERSION, '<=')) {
            return $transient;
        }

        $transient->response[$basename] = (object) [
            'slug' => self::SLUG,
            'plugin' => $basename,
            'new_version' => $latest,
            'package' => self::package_url($latest),
            'url' => 'https://serhandemirel.com',
        ];

        return $transient;
    }

    public static function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ! isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }
        $latest = self::latest();

        return (object) [
            'name' => 'ScreamingRabbit Connector',
            'slug' => self::SLUG,
            'version' => $latest ?: SR_CONNECTOR_VERSION,
            'author' => '<a href="https://serhandemirel.com">Serhan Demirel</a>',
            'homepage' => 'https://serhandemirel.com',
            'sections' => ['description' => 'ScreamingRabbit hesabınıza bağlanan salt okunur eklenti. Yeni sürüm bağlı olduğunuz ScreamingRabbit panelinden gelir.'],
            'download_link' => $latest ? self::package_url($latest) : null,
        ];
    }

    /** Newest version the paired app offers, checked at most every 12 hours; null when not paired or unreachable. */
    private static function latest()
    {
        $app = self::app();
        if ($app === '') {
            return null;
        }
        $cached = get_transient('sr_connector_latest');
        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $response = wp_remote_get($app.'/wp-baglanti/surum', ['timeout' => 10]);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $version = is_array($body) && isset($body['version']) && preg_match('/^\d+\.\d+\.\d+$/', (string) $body['version']) ? (string) $body['version'] : '';
        set_transient('sr_connector_latest', $version, self::CHECK_HOURS * HOUR_IN_SECONDS);

        return $version === '' ? null : $version;
    }

    private static function package_url($version)
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return add_query_arg([
            'v' => $version,
            's' => $host,
            't' => hash_hmac('sha256', "package\n".$version."\n".$host, SR_Settings::secret()),
        ], self::app().'/wp-baglanti/paket.zip');
    }

    private static function app()
    {
        $option = get_option('sr_connector');

        return is_array($option) && isset($option['app']) && strpos((string) $option['app'], 'https://') === 0 ? rtrim((string) $option['app'], '/') : '';
    }
}
