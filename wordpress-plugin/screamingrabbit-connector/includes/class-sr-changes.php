<?php

/**
 * The only changes the plugin can make (docs/07 WP-3): a page's SEO title, meta description and noindex, written into
 * the active SEO plugin's own fields. Nothing happens unless a site administrator has switched on "ScreamingRabbit can
 * apply changes" in Settings → ScreamingRabbit. Every request carries the value it expects to replace; if the value
 * changed in the meantime the change is refused as a conflict. The last 200 changes are kept in a log on the site.
 */
defined('ABSPATH') || exit;

class SR_Changes
{
    const FIELDS = ['title', 'description', 'noindex'];

    const LOG_OPTION = 'sr_change_log';

    const LOG_SIZE = 200;

    /** Current values for a URL, so the app can show what a change replaces. */
    public static function meta(WP_REST_Request $request)
    {
        $data = json_decode($request->get_body(), true);
        $post_id = self::post_id(is_array($data) && isset($data['url']) ? (string) $data['url'] : '');
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $values = ['post_id' => $post_id, 'seo_plugin' => self::seo_plugin(), 'writes_enabled' => SR_Settings::writes_enabled()];
        foreach (self::FIELDS as $field) {
            $values[$field] = self::read($post_id, $field);
        }

        return rest_ensure_response($values);
    }

    public static function apply(WP_REST_Request $request)
    {
        if (! SR_Settings::writes_enabled()) {
            return new WP_Error('sr_writes_disabled', 'Writes are not enabled on this site.', ['status' => 403]);
        }
        $data = json_decode($request->get_body(), true);
        if (! is_array($data) || ! in_array(isset($data['field']) ? $data['field'] : '', self::FIELDS, true) || ! array_key_exists('before', $data) || ! array_key_exists('after', $data)) {
            return new WP_Error('sr_bad_request', 'Bad change request.', ['status' => 422]);
        }
        $post_id = self::post_id(isset($data['url']) ? (string) $data['url'] : '');
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        if (self::seo_plugin() === null) {
            return new WP_Error('sr_no_seo_plugin', 'Yoast SEO or Rank Math is needed.', ['status' => 422]);
        }

        $field = $data['field'];
        $after = self::clean($field, $data['after']);
        $current = self::read($post_id, $field);
        if ($current !== (string) $data['before']) {
            return new WP_Error('sr_conflict', 'The value changed on the site.', ['status' => 409, 'current' => $current]);
        }

        self::write($post_id, $field, $after);
        self::log([
            'id' => isset($data['change_id']) ? (int) $data['change_id'] : 0,
            'time' => gmdate('c'),
            'post_id' => $post_id,
            'field' => $field,
            'before' => $current,
            'after' => $after,
        ]);

        return rest_ensure_response(['post_id' => $post_id, 'before' => $current, 'after' => self::read($post_id, $field)]);
    }

    /** Yoast SEO or Rank Math, whichever is active; null when neither. */
    public static function seo_plugin()
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (class_exists('RankMath')) {
            return 'rankmath';
        }

        return null;
    }

    private static function post_id($url)
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($url === '' || strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== strtolower((string) $host)) {
            return new WP_Error('sr_not_this_site', 'The URL is not on this site.', ['status' => 422]);
        }
        $post_id = url_to_postid($url);
        if (! $post_id && untrailingslashit($url) === untrailingslashit(home_url()) && get_option('show_on_front') === 'page') {
            $post_id = (int) get_option('page_on_front');
        }
        if (! $post_id || get_post_status($post_id) !== 'publish') {
            return new WP_Error('sr_not_a_post', 'No published post or page at this URL.', ['status' => 404]);
        }

        return (int) $post_id;
    }

    /** title and description as stored (may contain %%variables%%); noindex as "noindex", "index" or "" (default). */
    private static function read($post_id, $field)
    {
        $plugin = self::seo_plugin();
        if ($plugin === 'yoast') {
            if ($field === 'noindex') {
                $value = (string) get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);

                return $value === '1' ? 'noindex' : ($value === '2' ? 'index' : '');
            }

            return (string) get_post_meta($post_id, $field === 'title' ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc', true);
        }
        if ($plugin === 'rankmath') {
            if ($field === 'noindex') {
                $robots = (array) get_post_meta($post_id, 'rank_math_robots', true);

                return in_array('noindex', $robots, true) ? 'noindex' : (in_array('index', $robots, true) ? 'index' : '');
            }

            return (string) get_post_meta($post_id, $field === 'title' ? 'rank_math_title' : 'rank_math_description', true);
        }

        return '';
    }

    private static function write($post_id, $field, $value)
    {
        if (self::seo_plugin() === 'yoast') {
            if ($field === 'noindex') {
                $value === '' ? delete_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex')
                    : update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $value === 'noindex' ? '1' : '2');
            } else {
                $key = $field === 'title' ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc';
                $value === '' ? delete_post_meta($post_id, $key) : update_post_meta($post_id, $key, $value);
            }
        } else {
            if ($field === 'noindex') {
                $robots = array_values(array_diff((array) get_post_meta($post_id, 'rank_math_robots', true), ['index', 'noindex']));
                if ($value !== '') {
                    $robots[] = $value;
                }
                update_post_meta($post_id, 'rank_math_robots', $robots);
            } else {
                $key = $field === 'title' ? 'rank_math_title' : 'rank_math_description';
                $value === '' ? delete_post_meta($post_id, $key) : update_post_meta($post_id, $key, $value);
            }
        }
        clean_post_cache($post_id);
    }

    private static function clean($field, $value)
    {
        if ($field === 'noindex') {
            return in_array($value, ['noindex', 'index'], true) ? $value : '';
        }

        return mb_substr(sanitize_text_field((string) $value), 0, $field === 'title' ? 300 : 1000);
    }

    private static function log($entry)
    {
        $log = get_option(self::LOG_OPTION, []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, $entry);
        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_SIZE), false);
    }
}
