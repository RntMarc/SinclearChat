<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;
use SinclearChat\Helper\UuidV7;

final class AuthCode
{
    public const CODE_TTL_SECONDS = 300;

    public static function create(
        string $userId,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $redirectUri,
    ): string {
        $db = Database::getConnection();

        $code = self::generateCode();
        $expiresAt = date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS);

        $stmt = $db->prepare(
            'INSERT INTO auth_codes (code, user_id, code_challenge, code_challenge_method, redirect_uri, expires_at)
             VALUES (:code, :user_id, :code_challenge, :code_challenge_method, :redirect_uri, :expires_at)'
        );
        $stmt->execute([
            ':code' => $code,
            ':user_id' => $userId,
            ':code_challenge' => $codeChallenge,
            ':code_challenge_method' => $codeChallengeMethod,
            ':redirect_uri' => $redirectUri,
            ':expires_at' => $expiresAt,
        ]);

        return $code;
    }

    public static function findValid(string $code): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT code, user_id, code_challenge, code_challenge_method, redirect_uri, expires_at, used_at
             FROM auth_codes
             WHERE code = :code
             LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if ($row['used_at'] !== null) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    public static function markUsed(string $code): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE auth_codes SET used_at = NOW() WHERE code = :code');
        $stmt->execute([':code' => $code]);
    }

    private static function generateCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
