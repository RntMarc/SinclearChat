<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;
use SinclearChat\Helper\UuidV7;

final class Device
{
    public static function register(
        string $userId,
        string $platform,
        string $deviceId,
        string $pushToken,
        ?string $appVersion = null,
    ): string {
        $db = Database::getConnection();
        $id = UuidV7::generate();
        $idBytes = UuidV7::toBytes($id);

        $stmt = $db->prepare(
            'INSERT INTO user_devices (id, user_id, platform, device_id, push_token, app_version, last_access_at)
             VALUES (:id, :user_id, :platform, :device_id, :push_token, :app_version, NOW())
             ON DUPLICATE KEY UPDATE
                push_token = VALUES(push_token),
                app_version = VALUES(app_version),
                last_access_at = NOW()'
        );
        $stmt->execute([
            ':id' => $idBytes,
            ':user_id' => $userId,
            ':platform' => $platform,
            ':device_id' => $deviceId,
            ':push_token' => $pushToken,
            ':app_version' => $appVersion,
        ]);

        $stmt = $db->prepare(
            'SELECT HEX(id) AS id FROM user_devices
             WHERE user_id = :user_id AND platform = :platform AND device_id = :device_id
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':platform' => $platform,
            ':device_id' => $deviceId,
        ]);
        $row = $stmt->fetch();
        return $row ? (string) $row['id'] : $id;
    }

    public static function listForUser(string $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT HEX(id) AS id, platform, device_id, app_version, last_access_at, created_at
             FROM user_devices WHERE user_id = :user_id ORDER BY last_access_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll();
        return array_map(static function (array $row): array {
            return [
                'id' => (string) $row['id'],
                'platform' => (string) $row['platform'],
                'device_id' => (string) $row['device_id'],
                'app_version' => $row['app_version'],
                'last_access_at' => $row['last_access_at'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);
    }

    public static function delete(string $id, string $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'DELETE FROM user_devices WHERE id = UNHEX(:id) AND user_id = :user_id'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function deleteAllForUser(string $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM user_devices WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        return $stmt->rowCount();
    }
}
