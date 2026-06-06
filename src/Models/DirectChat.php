<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;
use SinclearChat\Helper\DirectChatPair;
use SinclearChat\Helper\UuidV7;

final class DirectChat
{
    public static function findOrCreate(string $userA, string $userB): array
    {
        [$low, $high] = DirectChatPair::normalize($userA, $userB);
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT HEX(id) AS id, user_a_id, user_b_id, created_at, last_message_at
             FROM direct_chats
             WHERE user_a_id = :low AND user_b_id = :high
             LIMIT 1'
        );
        $stmt->execute([':low' => $low, ':high' => $high]);
        $row = $stmt->fetch();

        if ($row) {
            return self::formatRow($row);
        }

        $id = UuidV7::generate();
        $idBytes = UuidV7::toBytes($id);

        $stmt = $db->prepare(
            'INSERT INTO direct_chats (id, user_a_id, user_b_id) VALUES (:id, :low, :high)'
        );
        $stmt->execute([':id' => $idBytes, ':low' => $low, ':high' => $high]);

        return [
            'id' => $id,
            'user_a_id' => $low,
            'user_b_id' => $high,
            'created_at' => date('c'),
            'last_message_at' => null,
        ];
    }

    public static function findById(string $id): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/i', $id)) {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT HEX(id) AS id, user_a_id, user_b_id, created_at, last_message_at
             FROM direct_chats WHERE id = UNHEX(:id) LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return self::formatRow($row);
    }

    public static function listForUser(string $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT HEX(dc.id) AS id, dc.user_a_id, dc.user_b_id, dc.created_at, dc.last_message_at
             FROM direct_chats dc
             WHERE dc.user_a_id = :user_id OR dc.user_b_id = :user_id
             ORDER BY dc.last_message_at IS NULL, dc.last_message_at DESC, dc.created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll();
        return array_map([self::class, 'formatRow'], $rows);
    }

    public static function updateLastMessageAt(string $id): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE direct_chats SET last_message_at = NOW(6) WHERE id = UNHEX(:id)');
        $stmt->execute([':id' => $id]);
    }

    public static function isMember(string $chatId, string $userId): bool
    {
        if (!preg_match('/^[0-9a-f]{32}$/i', $chatId)) {
            return false;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT 1 FROM direct_chats
             WHERE id = UNHEX(:id) AND (user_a_id = :user_id OR user_b_id = :user_id)
             LIMIT 1'
        );
        $stmt->execute([':id' => $chatId, ':user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function deleteForUser(string $chatId, string $userId): bool
    {
        if (!preg_match('/^[0-9a-f]{32}$/i', $chatId)) {
            return false;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'DELETE FROM direct_chats
             WHERE id = UNHEX(:id) AND (user_a_id = :user_id OR user_b_id = :user_id)'
        );
        $stmt->execute([':id' => $chatId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function getPartnerId(string $chatId, string $userId): ?string
    {
        $chat = self::findById($chatId);
        if ($chat === null) {
            return null;
        }
        if ($chat['user_a_id'] === $userId) {
            return $chat['user_b_id'];
        }
        if ($chat['user_b_id'] === $userId) {
            return $chat['user_a_id'];
        }
        return null;
    }

    private static function formatRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'user_a_id' => (string) $row['user_a_id'],
            'user_b_id' => (string) $row['user_b_id'],
            'created_at' => self::formatTimestamp($row['created_at']),
            'last_message_at' => self::formatTimestamp($row['last_message_at']),
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
