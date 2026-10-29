<?php

/**
 * What the plugin reports: versions, plugins and themes with their update status, server environment, settings that
 * matter for SEO and security, and content counts. No user names, e-mail addresses, comments or orders are collected.
 */
defined('ABSPATH') || exit;

class SR_Inventory
{
    public static function collect()
    {
        global $wpdb;

        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        if (! function_exists('get_core_updates')) {
            require_once ABSPATH.'wp-admin/includes/update.php';
        }

        $core_update = null;
        $core_transient = get_site_transient('update_core');
        if (is_object($core_transient) && ! empty($core_transient->updates)) {
            foreach ($core_transient->updates as $offer) {
                if (isset($offer->response, $offer->current) && $offer->response === 'upgrade') {
                    $core_update = $offer->current;
                    break;
                }
            }
        }

        $users = count_users();
        $admins = isset($users['avail_roles']['administrator']) ? (int) $users['avail_roles']['administrator'] : 0;

        return [
            'plugin_version' => SR_CONNECTOR_VERSION,
            'site_url' => home_url('/'),
            'core' => [
                'version' => get_bloginfo('version'),
                'update' => $core_update,
                'locale' => get_locale(),
                'multisite' => is_multisite(),
            ],
            'environment' => [
                'php' => PHP_VERSION,
                'mysql' => $wpdb->db_version(),
                'memory_limit' => WP_MEMORY_LIMIT,
                'https' => strpos(home_url(), 'https://') === 0,
            ],
            'settings' => [
                'blog_public' => (bool) get_option('blog_public'),
                'permalink' => (string) get_option('permalink_structure'),
                'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
                'wp_debug_display' => defined('WP_DEBUG') && WP_DEBUG && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY),
                'file_edit_disabled' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
                'auto_update_core' => self::core_auto_updates(),
                'users_can_register' => (bool) get_option('users_can_register'),
                'admins' => $admins,
            ],
            'plugins' => self::plugins(),
            'themes' => self::themes(),
            'content' => self::content(),
            'seo' => self::seo(),
            'site_health' => self::site_health(),
            'integrity' => self::integrity(),
        ];
    }

    private static function plugins()
    {
        $updates = get_site_transient('update_plugins');
        $auto = (array) get_site_option('auto_update_plugins', []);
        $list = [];
        foreach (get_plugins() as $file => $data) {
            $list[] = [
                'file' => $file,
                'name' => $data['Name'],
                'version' => $data['Version'],
                'active' => is_plugin_active($file),
                'update' => isset($updates->response[$file]->new_version) ? $updates->response[$file]->new_version : null,
                'auto_update' => in_array($file, $auto, true),
            ];
        }

        return $list;
    }

    private static function themes()
    {
        $updates = get_site_transient('update_themes');
        $active = get_stylesheet();
        $parent = get_template();
        $list = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $list[] = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $slug === $active,
                'parent' => $slug === $parent && $parent !== $active,
                'update' => isset($updates->response[$slug]['new_version']) ? $updates->response[$slug]['new_version'] : null,
            ];
        }

        return $list;
    }

    private static function content()
    {
        $counts = [];
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if ($type === 'attachment') {
                continue;
            }
            $c = wp_count_posts($type);
            $counts[$type] = [
                'publish' => isset($c->publish) ? (int) $c->publish : 0,
                'draft' => isset($c->draft) ? (int) $c->draft : 0,
                'future' => isset($c->future) ? (int) $c->future : 0,
                'private' => isset($c->private) ? (int) $c->private : 0,
            ];
        }

        return $counts;
    }

    /**
     * Post types the SEO plugin keeps out of search results as a whole, and whether its XML sitemap is on.
     */
    private static function seo()
    {
        $types = array_diff(get_post_types(['public' => true], 'names'), ['attachment']);
        $noindex = [];
        $plugin = null;
        $sitemap = null;

        if (defined('WPSEO_VERSION')) {
            $plugin = 'Yoast SEO';
            $titles = (array) get_option('wpseo_titles', []);
            foreach ($types as $type) {
                if (! empty($titles['noindex-'.$type])) {
                    $noindex[] = $type;
                }
            }
            $wpseo = (array) get_option('wpseo', []);
            $sitemap = ! array_key_exists('enable_xml_sitemap', $wpseo) || (bool) $wpseo['enable_xml_sitemap'];
        } elseif (class_exists('RankMath')) {
            $plugin = 'Rank Math';
            $titles = (array) get_option('rank-math-options-titles', []);
            foreach ($types as $type) {
                $robots = isset($titles['pt_'.$type.'_robots']) ? (array) $titles['pt_'.$type.'_robots'] : [];
                if (! empty($titles['pt_'.$type.'_custom_robots']) && in_array('noindex', $robots, true)) {
                    $noindex[] = $type;
                }
            }
            $sitemap = in_array('sitemap', (array) get_option('rank_math_modules', []), true);
        }

        return ['plugin' => $plugin, 'noindex_types' => array_values($noindex), 'sitemap' => $sitemap];
    }

    /**
     * WordPress's own Site Health checks that run without outside requests; only the ones that need attention.
     */
    private static function site_health()
    {
        if (! class_exists('WP_Site_Health')) {
            if (! file_exists(ABSPATH.'wp-admin/includes/class-wp-site-health.php')) {
                return null;
            }
            require_once ABSPATH.'wp-admin/includes/admin.php';
            require_once ABSPATH.'wp-admin/includes/class-wp-site-health.php';
        }

        $health = WP_Site_Health::get_instance();
        $tests = WP_Site_Health::get_tests();
        $found = [];
        foreach (isset($tests['direct']) ? $tests['direct'] : [] as $key => $test) {
            if ($key === 'rest_availability') {
                continue; // makes a request back to the site itself
            }
            try {
                if (is_string($test['test']) && method_exists($health, 'get_test_'.$test['test'])) {
                    $result = call_user_func([$health, 'get_test_'.$test['test']]);
                } elseif (is_callable($test['test'])) {
                    $result = call_user_func($test['test']);
                } else {
                    continue;
                }
            } catch (Throwable $e) {
                continue;
            }
            if (is_array($result) && isset($result['status']) && in_array($result['status'], ['critical', 'recommended'], true)) {
                $found[] = ['test' => (string) $key, 'status' => $result['status'], 'label' => wp_strip_all_tags((string) $result['label'])];
            }
        }

        return $found;
    }

    /**
     * WordPress core files that differ from the official release or are missing, checked against wordpress.org
     * checksums at most twice a day. wp-content is not checked (themes and plugins live there).
     */
    private static function integrity()
    {
        $cached = get_transient('sr_core_integrity');
        if (is_array($cached) && isset($cached['version']) && $cached['version'] === get_bloginfo('version')) {
            return $cached;
        }

        $checksums = get_core_checksums(get_bloginfo('version'), 'en_US');
        if (! is_array($checksums)) {
            return null;
        }

        $modified = [];
        $missing = [];
        foreach ($checksums as $file => $md5) {
            if (strpos($file, 'wp-content/') === 0 || in_array($file, ['wp-config-sample.php', 'wp-includes/version.php', 'readme.html', 'license.txt'], true)) {
                continue;
            }
            $path = ABSPATH.$file;
            if (! file_exists($path)) {
                $missing[] = $file;
            } elseif (md5_file($path) !== $md5) {
                $modified[] = $file;
            }
        }

        $result = [
            'version' => get_bloginfo('version'),
            'checked_at' => gmdate('c'),
            'modified' => array_slice($modified, 0, 20),
            'modified_count' => count($modified),
            'missing' => array_slice($missing, 0, 20),
            'missing_count' => count($missing),
        ];
        set_transient('sr_core_integrity', $result, 12 * HOUR_IN_SECONDS);

        return $result;
    }

    private static function core_auto_updates()
    {
        if (defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED) {
            return 'off';
        }
        if (defined('WP_AUTO_UPDATE_CORE')) {
            return true === WP_AUTO_UPDATE_CORE ? 'all' : (false === WP_AUTO_UPDATE_CORE ? 'off' : (string) WP_AUTO_UPDATE_CORE);
        }

        return get_site_option('auto_update_core_major') === 'enabled' ? 'all' : 'minor';
    }
}
