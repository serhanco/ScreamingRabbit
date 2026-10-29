<?php

/**
 * Request signing shared with the ScreamingRabbit app: HMAC-SHA256 over the method, REST route, timestamp, nonce
 * and the body's SHA-256. Plain PHP with no WordPress calls, so the app's tests can load it and check both sides agree.
 */
if (! class_exists('SR_Signature')) {
    class SR_Signature
    {
        /** Requests older or newer than this many seconds are refused. */
        const MAX_SKEW = 300;

        public static function sign($secret, $method, $route, $timestamp, $nonce, $body)
        {
            $payload = implode("\n", [strtoupper($method), $route, (string) $timestamp, $nonce, hash('sha256', (string) $body)]);

            return hash_hmac('sha256', $payload, $secret);
        }

        /**
         * @return string|null Why the request is refused, or null when the signature and timestamp are good.
         */
        public static function problem($secret, $method, $route, $timestamp, $nonce, $body, $signature, $now)
        {
            if ((string) $secret === '') {
                return 'not_connected';
            }
            if (! ctype_digit((string) $timestamp) || abs((int) $now - (int) $timestamp) > self::MAX_SKEW) {
                return 'stale';
            }
            if (! preg_match('/^[A-Za-z0-9]{16,64}$/', (string) $nonce)) {
                return 'bad_nonce';
            }
            if (! hash_equals(self::sign($secret, $method, $route, $timestamp, $nonce, $body), (string) $signature)) {
                return 'bad_signature';
            }

            return null;
        }
    }
}
