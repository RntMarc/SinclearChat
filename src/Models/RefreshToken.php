<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;
use SinclearChat\Helper\UuidV7;

final class RefreshToken
{
    public const REFRESH_BYTES = 48;

    public static function createFamily(string $userId): string
    {
        $db = Database::getConnection();
        $familyId = UuidV7::generate();
        $familyIdBytes = UuidV7::toBytes($familyId);

        $stmt = $db->prepare(
            'INSERT INTO refresh_token_families (id, user_id) VALUES (:id, :user_id)'
        );
        $stmt->execute([
            ':id' => $familyIdBytes,
            ':user_id' => $userId,
        ]);

        return $familyId;
    }

    public static function issue(
        string $userId,
        string $familyId,
        ?string $parentId,
    ): array {
        $db = Database::getConnection();

        $tokenId = UuidV7::generate();
        $tokenIdBytes = UuidV7::toBytes($tokenId);
        $familyIdBytes = UuidV7::toBytes($familyId);
        $parentIdBytes = $parentId !== null ? UuidV7::toBytes($parentId) : null;

        $plaintext = self::generatePlaintext();
        $hash = self::hashToken($plaintext);

        $expiresAt = date('Y-m-d H:i:s', time() + self::getTtlSeconds());

        $stmt = $db->prepare(
            'INSERT INTO refresh_tokens
                (id, family_id, user_id, parent_id, token_hash, expires_at)
             VALUES
                (:id, :family_id, :user_id, :parent_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            ':id' => $tokenIdBytes,
            ':family_id' => $familyIdBytes,
            ':user_id' => $userId,
            ':parent_id' => $parentIdBytes,
            ':token_hash' => $hash,
            ':expires_at' => $expiresAt,
        ]);

        return [
            'id' => $tokenId,
            'plaintext' => $plaintext,
            'expires_at' => $expiresAt,
        ];
    }

    public static function findValid(string $plaintext): ?array
    {
        $db = Database::getConnection();
        $hash = self::hashToken($plaintext);

        $stmt = $db->prepare(
            'SELECT HEX(id) AS id, HEX(family_id) AS family_id, user_id, HEX(parent_id) AS parent_id,
                    expires_at, used_at, revoked_at
             FROM refresh_tokens
             WHERE token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }
        if ($row['revoked_at'] !== null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        return $row;
    }

    public static function markUsed(string $id): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE refresh_tokens SET used_at = NOW() WHERE id = UNHEX(:id) AND used_at IS NULL');
        $stmt->execute([':id' => $id]);
    }

    public static function revokeFamily(string $familyId, string $reason): void
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'UPDATE refresh_token_families
                 SET revoked_at = NOW(), revoked_reason = :reason
                 WHERE id = UNHEX(:id)'
            );
            $stmt->execute([':id' => $familyId, ':reason' => $reason]);

            $stmt = $db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = NOW()
                 WHERE family_id = UNHEX(:id) AND revoked_at IS NULL'
            );
            $stmt->execute([':id' => $familyId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function revokeAllFamiliesForUser(string $userId, string $reason): void
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'UPDATE refresh_token_families
                 SET revoked_at = NOW(), revoked_reason = :reason
                 WHERE user_id = :user_id AND revoked_at IS NULL'
            );
            $stmt->execute([':reason' => $reason, ':user_id' => $userId]);

            $stmt = $db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = NOW()
                 WHERE user_id = :user_id AND revoked_at IS NULL'
            );
            $stmt->execute([':user_id' => $userId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function generatePlaintext(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::REFRESH_BYTES)), '+/', '-_'), '=');
    }

    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function getTtlSeconds(): int
    {
        $config = \SinclearChat\Config::getInstance();
        return max(60, $config->getInt('JWT_API_REFRESH_TTL', 2592000));
    }
}
