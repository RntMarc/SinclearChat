<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;
use SinclearChat\Helper\UuidV7;

final class ReadReceipt
{
    public static function markRead(string $userId, string $chatId, string $chatType): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO chat_read_receipts (user_id, chat_id, chat_type, last_read_at)
             VALUES (:user_id, :chat_id, :chat_type, NOW(6))
             ON DUPLICATE KEY UPDATE last_read_at = NOW(6)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':chat_id' => $chatId,
            ':chat_type' => $chatType,
        ]);
    }

    public static function markMultipleRead(string $userId, array $entries): void
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO chat_read_receipts (user_id, chat_id, chat_type, last_read_at)
                 VALUES (:user_id, :chat_id, :chat_type, NOW(6))
                 ON DUPLICATE KEY UPDATE last_read_at = NOW(6)'
            );
            foreach ($entries as $entry) {
                $stmt->execute([
                    ':user_id' => $userId,
                    ':chat_id' => $entry['chat_id'],
                    ':chat_type' => $entry['chat_type'],
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function getLastReadAt(string $userId, string $chatId, string $chatType): ?string
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT last_read_at FROM chat_read_receipts
             WHERE user_id = :user_id AND chat_id = :chat_id AND chat_type = :chat_type
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':chat_id' => $chatId,
            ':chat_type' => $chatType,
        ]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public static function countUnreadForUser(string $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT
                COUNT(CASE WHEN m.chat_type = 'direct' AND (rr.last_read_at IS NULL OR m.created_at > rr.last_read_at) THEN 1 END) AS direct,
                COUNT(CASE WHEN m.chat_type = 'group'  AND (rr.last_read_at IS NULL OR m.created_at > rr.last_read_at) THEN 1 END) AS `group`
             FROM ChatMessages m
             LEFT JOIN chat_read_receipts rr
                ON rr.user_id = :user_id
                AND rr.chat_id = m.chat_id
                AND rr.chat_type = m.chat_type
             WHERE (m.chat_type = 'direct' AND (m.user_id = :user_id OR m.chat_id = :user_id))
                OR (m.chat_type = 'group' AND EXISTS (
                    SELECT 1 FROM ChatRoomMembers crm
                    WHERE crm.chat_room_id = m.chat_id AND crm.user_id = :user_id
                ))"
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch() ?: ['direct' => 0, 'group' => 0];
        $row['direct'] = (int) ($row['direct'] ?? 0);
        $row['group'] = (int) ($row['group'] ?? 0);
        $row['total'] = $row['direct'] + $row['group'];
        return $row;
    }
}
