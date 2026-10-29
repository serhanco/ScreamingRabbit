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
