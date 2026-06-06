<?php

declare(strict_types=1);

namespace SinclearChat\Http;

use SinclearChat\Config;

final class NextJsClient
{
    public static function mintAccessToken(string $userId): array
    {
        $config = Config::getInstance();
        $url = rtrim((string) $config->get('NEXTJS_INTERNAL_URL', 'http://localhost:3000'), '/');
        $endpoint = $url . '/api/internal/chat/v2/mint-token';
        $secret = (string) $config->get('AUTH_INTERNAL_SECRET', '');

        if ($secret === '') {
            return ['error' => 'AUTH_INTERNAL_SECRET not configured'];
        }

        $body = json_encode(['user_id' => $userId], JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $payload = $timestamp . '.POST./api/internal/chat/v2/mint-token.' . $body;
        $signature = hash_hmac('sha256', $payload, $secret);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Hub-Timestamp: ' . $timestamp,
                'X-Hub-Signature: ' . $signature,
            ],
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['error' => 'Failed to call Next.js: ' . $error];
        }

        $data = json_decode((string) $response, true);
        if ($httpCode !== 200 || !is_array($data)) {
            return ['error' => is_array($data) ? ($data['error'] ?? 'Unknown error') : 'Bad response from Next.js'];
        }

        return ['token' => (string) ($data['token'] ?? '')];
    }

    public static function fetchProfile(string $userId): ?array
    {
        $config = Config::getInstance();
        $url = rtrim((string) $config->get('NEXTJS_INTERNAL_URL', 'http://localhost:3000'), '/');
        $endpoint = $url . '/api/internal/chat/v2/refresh-profile';
        $secret = (string) $config->get('AUTH_INTERNAL_SECRET', '');

        if ($secret === '') {
            return null;
        }

        $body = json_encode(['user_id' => $userId], JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $payload = $timestamp . '.POST./api/internal/chat/v2/refresh-profile.' . $body;
        $signature = hash_hmac('sha256', $payload, $secret);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Hub-Timestamp: ' . $timestamp,
                'X-Hub-Signature: ' . $signature,
            ],
            CURLOPT_TIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode((string) $response, true);
        return is_array($data) ? $data : null;
    }
}
