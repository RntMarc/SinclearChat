<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;

final class UserProfile
{
    public static function upsert(
        string $userId,
        ?string $displayName = null,
        ?string $avatar = null,
        ?string $statusMessage = null,
    ): void {
        $db = Database::getConnection();
        $sets = [];
        $params = [':user_id' => $userId];

        if ($displayName !== null) {
            $sets[] = 'display_name = :display_name';
            $params[':display_name'] = $displayName;
        }
        if ($avatar !== null) {
            $sets[] = 'avatar = :avatar';
            $params[':avatar'] = $avatar;
        }
        if ($statusMessage !== null) {
            $sets[] = 'status_message = :status_message';
            $params[':status_message'] = $statusMessage;
        }

        if (empty($sets)) {
            return;
        }

        $sql = 'INSERT INTO user_profiles (user_id, display_name) VALUES (:user_id, :display_name_fb)
                ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);

        $params[':display_name_fb'] = $params[':display_name'] ?? 'Unknown';
        $db->prepare($sql)->execute($params);
    }

    public static function findById(string $userId): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT user_id, display_name, avatar, status_message, token_version, created_at, updated_at
             FROM user_profiles WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return self::formatRow($row);
    }

    public static function findByIds(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        $db = Database::getConnection();
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare(
            "SELECT user_id, display_name, avatar, status_message, token_version, created_at, updated_at
             FROM user_profiles WHERE user_id IN ({$placeholders})"
        );
        $stmt->execute($userIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['user_id']] = self::formatRow($row);
        }
        return $result;
    }

    public static function getTokenVersion(string $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT token_version FROM user_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? 1 : (int) $value;
    }

    public static function incrementTokenVersion(string $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO user_profiles (user_id, display_name, token_version)
             VALUES (:user_id, :display_name, 1)
             ON DUPLICATE KEY UPDATE token_version = token_version + 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':display_name' => 'Unknown',
        ]);
        return self::getTokenVersion($userId);
    }

    private static function formatRow(array $row): array
    {
        return [
            'id' => (string) $row['user_id'],
            'display_name' => (string) $row['display_name'],
            'avatar' => $row['avatar'],
            'status_message' => $row['status_message'],
            'token_version' => (int) $row['token_version'],
            'created_at' => self::formatTimestamp($row['created_at']),
            'updated_at' => self::formatTimestamp($row['updated_at']),
        ];
    }

    private static function formatTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;
        if ($string === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($string, new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d\TH:i:s.u\Z');
        } catch (\Throwable) {
            return $string;
        }
    }
}
