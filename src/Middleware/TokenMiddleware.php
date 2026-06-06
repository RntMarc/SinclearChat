<?php

declare(strict_types=1);

namespace SinclearChat\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use SinclearChat\Config;
use SinclearChat\Database;
use SinclearChat\Response;

final class TokenMiddleware
{
    private const ALG = 'RS256';
    private const LEEWAY_SECONDS = 30;

    private static ?array $cachedClaims = null;

    public static function requireBearer(): ?array
    {
        $headers = self::getRequestHeaders();
        $authHeader = $headers['authorization'] ?? '';

        if (!is_string($authHeader) || $authHeader === '') {
            Response::unauthorized('Missing Authorization header')->send();
            exit;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            Response::unauthorized('Invalid Authorization header format')->send();
            exit;
        }

        $token = trim($matches[1]);
        if ($token === '') {
            Response::unauthorized('Empty bearer token')->send();
            exit;
        }

        $claims = self::decode($token);
        if ($claims === null) {
            Response::unauthorized('Invalid or expired token')->send();
            exit;
        }

        self::$cachedClaims = $claims;
        return $claims;
    }

    public static function decode(string $token): ?array
    {
        $config = Config::getInstance();
        $publicKey = $config->get('JWT_API_PUBLIC_KEY');
        $issuer = $config->get('JWT_API_ISSUER');
        $audience = $config->get('JWT_API_AUDIENCE', 'chat-api');

        if (!is_string($publicKey) || $publicKey === '') {
            error_log('[SinclearChat] JWT_API_PUBLIC_KEY is not configured');
            return null;
        }

        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $decoded = JWT::decode($token, new Key($publicKey, self::ALG));
        } catch (\Throwable $e) {
            error_log('[SinclearChat] JWT decode failed: ' . $e->getMessage());
            return null;
        }

        $claims = (array) $decoded;

        if (!isset($claims['iss']) || $claims['iss'] !== $issuer) {
            error_log('[SinclearChat] JWT issuer mismatch');
            return null;
        }

        $aud = $claims['aud'] ?? null;
        $audValid = is_string($aud) ? $aud === $audience : (is_array($aud) && in_array($audience, $aud, true));
        if (!$audValid) {
            error_log('[SinclearChat] JWT audience mismatch');
            return null;
        }

        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
            return null;
        }

        if (!isset($claims['jti']) || !is_string($claims['jti']) || $claims['jti'] === '') {
            return null;
        }

        if (self::isJtiBlacklisted((string) $claims['jti'])) {
            return null;
        }

        $tokenVersion = (int) ($claims['token_version'] ?? 0);
        if ($tokenVersion > 0) {
            $profileVersion = self::getUserTokenVersion((string) $claims['sub']);
            if ($profileVersion !== null && $tokenVersion < $profileVersion) {
                return null;
            }
        }

        return [
            'sub' => (string) $claims['sub'],
            'iss' => (string) $claims['iss'],
            'aud' => $aud,
            'iat' => (int) ($claims['iat'] ?? 0),
            'exp' => (int) ($claims['exp'] ?? 0),
            'jti' => (string) $claims['jti'],
            'token_version' => $tokenVersion,
        ];
    }

    public static function getClaims(): ?array
    {
        return self::$cachedClaims;
    }

    public static function getUserId(): string
    {
        $claims = self::$cachedClaims;
        if ($claims === null) {
            throw new \RuntimeException('Token claims not available; requireBearer() must be called first');
        }
        return $claims['sub'];
    }

    public static function getJti(): string
    {
        $claims = self::$cachedClaims;
        if ($claims === null) {
            throw new \RuntimeException('Token claims not available; requireBearer() must be called first');
        }
        return $claims['jti'];
    }

    public static function isJtiBlacklisted(string $jti): bool
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT 1 FROM jti_blacklist WHERE jti = :jti AND expires_at > NOW() LIMIT 1');
            $stmt->execute([':jti' => $jti]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function getUserTokenVersion(string $userId): ?int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT token_version FROM user_profiles WHERE user_id = :uid LIMIT 1');
            $stmt->execute([':uid' => $userId]);
            $value = $stmt->fetchColumn();
            return $value === false ? null : (int) $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }
}
