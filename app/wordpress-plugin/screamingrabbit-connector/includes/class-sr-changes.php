<?php

/**
 * The only changes the plugin can make (docs/07 WP-3): a page's SEO title, meta description and noindex (written into
 * the active SEO plugin's own fields), an image's alt text in the media library, and 301 redirects kept by this plugin. Nothing happens unless a site administrator has switched on "ScreamingRabbit can
 * apply changes" in Settings → ScreamingRabbit. Every request carries the value it expects to replace; if the value
 * changed in the meantime the change is refused as a conflict. The last 200 changes are kept in a log on the site.
 */
defined('ABSPATH') || exit;

class SR_Changes
{
    const FIELDS = ['title', 'description', 'noindex', 'alt', 'redirect'];

    const POST_FIELDS = ['title', 'description', 'noindex'];

    const REDIRECTS_OPTION = 'sr_redirects';

    const MAX_REDIRECTS = 500;

    const LOG_OPTION = 'sr_change_log';

    const LOG_SIZE = 200;

    /** Current values for a URL, so the app can show what a change replaces. */
    public static function meta(WP_REST_Request $request)
    {
        $data = json_decode($request->get_body(), true);
        $url = is_array($data) && isset($data['url']) ? (string) $data['url'] : '';
        if (! self::on_this_site($url)) {
            return new WP_Error('sr_not_this_site', 'The URL is not on this site.', ['status' => 422]);
        }

        // Only the fields this URL has: SEO fields for a published post or page, alt for an image; a redirect always.
        $values = ['seo_plugin' => self::seo_plugin(), 'writes_enabled' => SR_Settings::writes_enabled(), 'redirect' => self::read_redirect($url)];
        $post_id = self::post_id($url);
        if (! is_wp_error($post_id)) {
            $values['post_id'] = $post_id;
            foreach (self::POST_FIELDS as $field) {
                $values[$field] = self::read($post_id, $field);
            }
        }
        $attachment_id = self::attachment_id($url);
        if ($attachment_id) {
            $values['attachment_id'] = $attachment_id;
            $values['alt'] = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
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
        $url = isset($data['url']) ? (string) $data['url'] : '';
        if (! self::on_this_site($url)) {
            return new WP_Error('sr_not_this_site', 'The URL is not on this site.', ['status' => 422]);
        }
        $field = $data['field'];
        $after = self::clean($field, $data['after']);

        if ($field === 'redirect') {
            if ($after !== '' && (! self::on_this_site($after) || self::path_key($after) === self::path_key($url))) {
                return new WP_Error('sr_bad_target', 'Redirects must point to another address on this site.', ['status' => 422]);
            }
            $target_id = 0;
            $current = self::read_redirect($url);
        } elseif ($field === 'alt') {
            $target_id = self::attachment_id($url);
            if (! $target_id) {
                return new WP_Error('sr_not_an_image', 'No media library image at this URL.', ['status' => 404]);
            }
            $current = (string) get_post_meta($target_id, '_wp_attachment_image_alt', true);
        } else {
            $target_id = self::post_id($url);
            if (is_wp_error($target_id)) {
                return $target_id;
            }
            if (self::seo_plugin() === null) {
                return new WP_Error('sr_no_seo_plugin', 'Yoast SEO or Rank Math is needed.', ['status' => 422]);
            }
            $current = self::read($target_id, $field);
        }

        if ($current !== (string) $data['before']) {
            return new WP_Error('sr_conflict', 'The value changed on the site.', ['status' => 409, 'current' => $current]);
        }

        if ($field === 'redirect') {
            $saved = self::write_redirect($url, $after);
            if (is_wp_error($saved)) {
                return $saved;
            }
            $now = self::read_redirect($url);
        } elseif ($field === 'alt') {
            $after === '' ? delete_post_meta($target_id, '_wp_attachment_image_alt') : update_post_meta($target_id, '_wp_attachment_image_alt', $after);
            $now = (string) get_post_meta($target_id, '_wp_attachment_image_alt', true);
        } else {
            self::write($target_id, $field, $after);
            $now = self::read($target_id, $field);
        }
        self::log([
            'id' => isset($data['change_id']) ? (int) $data['change_id'] : 0,
            'time' => gmdate('c'),
            'url' => $url,
            'field' => $field,
            'before' => $current,
            'after' => $now,
        ]);

        return rest_ensure_response(['before' => $current, 'after' => $now]);
    }

    /** Sends visitors and bots from a redirected address to its target with a 301. */
    public static function redirect()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        $redirects = get_option(self::REDIRECTS_OPTION, []);
        if (! is_array($redirects) || $redirects === []) {
            return;
        }
        $request = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $key = self::path_key('http://localhost'.$request);
        if (isset($redirects[$key])) {
            wp_redirect($redirects[$key], 301, 'ScreamingRabbit');
            exit;
        }
    }

    /** @return array<string, string> source path => target URL */
    public static function redirects()
    {
        $redirects = get_option(self::REDIRECTS_OPTION, []);

        return is_array($redirects) ? $redirects : [];
    }

    private static function read_redirect($url)
    {
        $redirects = self::redirects();
        $key = self::path_key($url);

        return isset($redirects[$key]) ? (string) $redirects[$key] : '';
    }

    private static function write_redirect($url, $target)
    {
        $redirects = self::redirects();
        $key = self::path_key($url);
        if ($target === '') {
            unset($redirects[$key]);
        } else {
            if (! isset($redirects[$key]) && count($redirects) >= self::MAX_REDIRECTS) {
                return new WP_Error('sr_too_many_redirects', 'Redirect limit reached.', ['status' => 422]);
            }
            $redirects[$key] = $target;
        }
        update_option(self::REDIRECTS_OPTION, $redirects, true);

        return true;
    }

    /** Path and query without the trailing slash: "/eski-sayfa", "/urun?id=3", "" for the home page. */
    private static function path_key($url)
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        return untrailingslashit($path).($query !== '' ? '?'.$query : '');
    }

    private static function on_this_site($url)
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        return $url !== '' && preg_match('#^https?://#i', $url) && strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === $host;
    }

    /** Media library image for a file URL, also for its resized copies ("photo-300x200.jpg"). */
    private static function attachment_id($url)
    {
        if (! preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', (string) wp_parse_url($url, PHP_URL_PATH))) {
            return 0;
        }
        $id = attachment_url_to_postid($url);
        if (! $id) {
            $id = attachment_url_to_postid(preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $url));
        }

        return (int) $id;
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
        if ($field === 'redirect') {
            return esc_url_raw(trim((string) $value), ['http', 'https']);
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
