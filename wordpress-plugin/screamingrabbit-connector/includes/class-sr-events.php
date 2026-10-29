<?php

/**
 * Tells the paired app when public content is published, updated or taken down, so it can re-check that URL within
 * minutes instead of waiting for the next crawl. Sends only the URL, post type and event; the request is signed and
 * does not wait for an answer, so saving a post is never slowed down.
 */
defined('ABSPATH') || exit;

class SR_Events
{
    const ROUTE = '/wp-baglanti/olay';

    public static function register()
    {
        add_action('transition_post_status', [self::class, 'transition'], 10, 3);
    }

    public static function transition($new_status, $old_status, $post)
    {
        if (! $post instanceof WP_Post || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }
        $type = get_post_type_object($post->post_type);
        if (! $type || ! $type->public || $post->post_type === 'attachment') {
            return;
        }

        if ($new_status === 'publish') {
            $event = $old_status === 'publish' ? 'updated' : 'published';
        } elseif ($old_status === 'publish') {
            $event = 'unpublished';
        } else {
            return;
        }

        // One notice per post a minute: the block editor saves a post more than once.
        $key = 'sr_event_'.$post->ID;
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, MINUTE_IN_SECONDS);

        $url = $event === 'unpublished' ? self::public_url($post) : get_permalink($post);
        if (! $url) {
            return;
        }
        self::send(['event' => $event, 'url' => $url, 'post_type' => $post->post_type]);
    }

    /** The address the post had while public; get_permalink() of a draft is ?p=123. */
    private static function public_url($post)
    {
        $clone = clone $post;
        $clone->post_status = 'publish';
        if ($clone->post_name === '') {
            $clone->post_name = sanitize_title($clone->post_title, (string) $clone->ID);
        }

        return get_permalink($clone);
    }

    private static function send($payload)
    {
        $option = get_option('sr_connector');
        $secret = SR_Settings::secret();
        if ($secret === '' || ! is_array($option) || empty($option['app']) || strpos((string) $option['app'], 'https://') !== 0) {
            return;
        }

        $body = wp_json_encode($payload);
        $timestamp = (string) time();
        $nonce = wp_generate_password(32, false, false);

        wp_remote_post(rtrim((string) $option['app'], '/').self::ROUTE, [
            'blocking' => false,
            'timeout' => 1,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-SR-Site' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
                'X-SR-Timestamp' => $timestamp,
                'X-SR-Nonce' => $nonce,
                'X-SR-Signature' => SR_Signature::sign($secret, 'POST', self::ROUTE, $timestamp, $nonce, $body),
            ],
            'body' => $body,
        ]);
    }
}
