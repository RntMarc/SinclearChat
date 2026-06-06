<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;

final class Presence
{
    public const ALLOWED_STATUSES = ['online', 'offline'];

    public static function setStatus(string $userId, string $status): void
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO user_presence (user_id, status, last_seen_at)
             VALUES (:user_id, :status, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), last_seen_at = NOW()'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':status' => $status,
        ]);
    }

    public static function getStatus(string $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT status, last_seen_at FROM user_presence WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['status' => 'offline', 'last_seen_at' => null];
        }
        return [
            'status' => (string) $row['status'],
            'last_seen_at' => self::formatTimestamp($row['last_seen_at']),
        ];
    }

    public static function getStatuses(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        $db = Database::getConnection();
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare(
            "SELECT user_id, status, last_seen_at FROM user_presence WHERE user_id IN ({$placeholders})"
        );
        $stmt->execute($userIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['user_id']] = [
                'status' => (string) $row['status'],
                'last_seen_at' => self::formatTimestamp($row['last_seen_at']),
            ];
        }
        foreach ($userIds as $id) {
            if (!isset($result[$id])) {
                $result[$id] = ['status' => 'offline', 'last_seen_at' => null];
            }
        }
        return $result;
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
