<?php

/**
 * REST endpoints under /screamingrabbit/v1 (reads, and the narrow change list in SR_Changes). Every request must carry a valid signature made with the
 * secret agreed at pairing; a nonce is accepted once, so a captured request cannot be replayed.
 */
defined('ABSPATH') || exit;

class SR_Api
{
    const NAMESPACE_V1 = 'screamingrabbit/v1';

    /** Requests per minute the plugin answers; a misbehaving caller cannot load the site. */
    const RATE_LIMIT = 60;

    public static function register()
    {
        register_rest_route(
            self::NAMESPACE_V1,
            '/ping',
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'ping'],
                'permission_callback' => [__CLASS__, 'authorize'],
            ]
        );
        register_rest_route(
            self::NAMESPACE_V1,
            '/inventory',
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'inventory'],
                'permission_callback' => [__CLASS__, 'authorize'],
            ]
        );
        register_rest_route(
            self::NAMESPACE_V1,
            '/meta',
            [
                'methods' => 'POST',
                'callback' => ['SR_Changes', 'meta'],
                'permission_callback' => [__CLASS__, 'authorize'],
            ]
        );
        register_rest_route(
            self::NAMESPACE_V1,
            '/change',
            [
                'methods' => 'POST',
                'callback' => ['SR_Changes', 'apply'],
                'permission_callback' => [__CLASS__, 'authorize'],
            ]
        );
    }

    public static function ping()
    {
        return rest_ensure_response(
            [
                'plugin_version' => SR_CONNECTOR_VERSION,
                'site_url' => home_url('/'),
            ]
        );
    }

    public static function inventory()
    {
        return rest_ensure_response(SR_Inventory::collect());
    }

    /**
     * @return true|WP_Error
     */
    public static function authorize(WP_REST_Request $request)
    {
        $minute = 'sr_rate_'.gmdate('YmdHi');
        $count = (int) get_transient($minute);
        if ($count >= self::RATE_LIMIT) {
            return new WP_Error('sr_rate_limited', 'Too many requests.', ['status' => 429]);
        }
        set_transient($minute, $count + 1, 120);

        $nonce = (string) $request->get_header('x_sr_nonce');
        $problem = SR_Signature::problem(
            SR_Settings::secret(),
            $request->get_method(),
            $request->get_route(),
            $request->get_header('x_sr_timestamp'),
            $nonce,
            $request->get_body(),
            $request->get_header('x_sr_signature'),
            time()
        );
        if ($problem === null && get_transient('sr_nonce_'.$nonce) !== false) {
            $problem = 'replayed';
        }
        if ($problem !== null) {
            return new WP_Error('sr_unauthorized', $problem, ['status' => 401]);
        }

        set_transient('sr_nonce_'.$nonce, 1, 2 * SR_Signature::MAX_SKEW);

        return true;
    }
}
