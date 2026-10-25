<?php

namespace App\Monitoring;

use Carbon\CarbonImmutable;

class SslCertificate
{
    /** Expiry of the certificate the host serves on port 443, or null if it cannot be read. */
    public static function expiresAt(string $host): ?CarbonImmutable
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $client = @stream_socket_client("ssl://{$host}:443", $errno, $error, MonitorRunner::TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, $context);

        if ($client === false) {
            return null;
        }

        $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);
        $info = $certificate ? openssl_x509_parse($certificate) : false;

        return $info && isset($info['validTo_time_t']) ? CarbonImmutable::createFromTimestamp($info['validTo_time_t']) : null;
    }
}
