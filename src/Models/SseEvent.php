<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;

final class SseEvent
{
    public static function emit(?string $userId, string $type, array $payload): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO sse_events (user_id, event_type, payload) VALUES (:user_id, :type, :payload)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $db->lastInsertId();
    }

    public static function fetchForUser(string $userId, ?int $afterId, int $limit = 200): array
    {
        $db = Database::getConnection();

        if ($afterId === null) {
            $stmt = $db->prepare(
                "SELECT id, user_id, event_type, payload, created_at
                 FROM sse_events
                 WHERE user_id = :user_id OR user_id IS NULL
                 ORDER BY id DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':user_id', $userId);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        } else {
            $stmt = $db->prepare(
                "SELECT id, user_id, event_type, payload, created_at
                 FROM sse_events
                 WHERE id > :after_id AND (user_id = :user_id OR user_id IS NULL)
                 ORDER BY id ASC
                 LIMIT :limit"
            );
            $stmt->bindValue(':after_id', $afterId, \PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(static function (array $row): array {
            $payload = $row['payload'];
            $decoded = is_string($payload) ? json_decode($payload, true) : null;
            return [
                'id' => (int) $row['id'],
                'user_id' => $row['user_id'] !== null ? (string) $row['user_id'] : null,
                'event_type' => (string) $row['event_type'],
                'payload' => is_array($decoded) ? $decoded : [],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    public static function getMaxIdForUser(string $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT COALESCE(MAX(id), 0) FROM sse_events WHERE user_id = :user_id OR user_id IS NULL"
        );
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }
}
