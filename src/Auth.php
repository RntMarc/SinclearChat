<?php

declare(strict_types=1);

namespace SinclearChat;

final class Auth
{
    private const MAX_TIMESTAMP_AGE_SECONDS = 300;

    public static function verify(string $method, string $uri, string $body, array $headers): bool
    {
        $config = Config::getInstance();
        $secret = $config->get('SHARED_SECRET');

        if (!is_string($secret) || $secret === '') {
            throw new \RuntimeException('SHARED_SECRET is not configured');
        }

        $prefix = (string) $config->get('HMAC_HEADER_PREFIX', 'X-Hub');
        $timestampHeader = $prefix . '-Timestamp';
        $signatureHeader = $prefix . '-Signature';

        $headers = array_change_key_case($headers, CASE_LOWER);

        $timestamp = $headers[strtolower($timestampHeader)] ?? '';
        $signature = $headers[strtolower($signatureHeader)] ?? '';

        if (!is_string($timestamp) || $timestamp === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        $ts = (int) $timestamp;
        if ($ts <= 0) {
            return false;
        }
        if (abs(time() - $ts) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            return false;
        }

        $payload = $timestamp . '.' . strtoupper($method) . '.' . $uri . '.' . $body;
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
