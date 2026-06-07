<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;
use SinclearChat\Helper\UuidV7;

final class Message
{
    public static function create(
        string $userId,
        string $chatId,
        string $chatType,
        string $body,
        ?string $attachmentType = null,
        ?string $attachmentBody = null,
        ?string $directChatId = null,
    ): array {
        $db = Database::getConnection();
        $id = UuidV7::generate();
        $idBytes = UuidV7::toBytes($id);

        $directChatIdBytes = $directChatId !== null ? UuidV7::toBytes($directChatId) : null;

        $stmt = $db->prepare(
            'INSERT INTO ChatMessages
                (id, user_id, chat_id, chat_type, direct_chat_id, body,
                 attachment_type, attachment_body)
             VALUES
                (:id, :user_id, :chat_id, :chat_type, :direct_chat_id, :body,
                 :attachment_type, :attachment_body)'
        );

        $stmt->execute([
            ':id'                  => $idBytes,
            ':user_id'             => $userId,
            ':chat_id'             => $chatId,
            ':chat_type'           => $chatType,
            ':direct_chat_id'      => $directChatIdBytes,
            ':body'                => $body,
            ':attachment_type'     => $attachmentType,
            ':attachment_body'     => $attachmentBody,
        ]);

        return self::findById($id);
    }

    public static function findById(string $id): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/i', $id)) {
            return null;
        }

        $db = Database::getConnection();
        $idBytes = UuidV7::toBytes($id);

        $stmt = $db->prepare(
            'SELECT id, user_id, chat_id, chat_type, direct_chat_id, body,
                    attachment_type, attachment_body, created_at
             FROM ChatMessages WHERE id = :id'
        );
        $stmt->execute([':id' => $idBytes]);
        $row = $stmt->fetch();

        return $row ? self::formatRow($row) : null;
    }

    public static function findDirectMessages(
        string $userId,
        string $chatPartnerId,
        ?string $after = null,
        ?string $before = null,
        int $limit = 50,
    ): array {
        $db = Database::getConnection();

        $conditions = [
            'chat_type = :chat_type',
            '((user_id = :user_id1 AND chat_id = :partner_id1) OR (user_id = :partner_id2 AND chat_id = :user_id2))',
        ];
        $params = [
            ':chat_type'   => 'direct',
            ':user_id1'    => $userId,
            ':partner_id1' => $chatPartnerId,
            ':partner_id2' => $chatPartnerId,
            ':user_id2'    => $userId,
        ];

        if ($after !== null) {
            $conditions[] = 'created_at > :after';
            $params[':after'] = $after;
        }

        if ($before !== null) {
            $conditions[] = 'created_at < :before';
            $params[':before'] = $before;
        }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT id, user_id, chat_id, chat_type, direct_chat_id, body,
                       attachment_type, attachment_body, created_at
                FROM ChatMessages WHERE {$where}
                ORDER BY created_at DESC
                LIMIT :limit";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return array_map([self::class, 'formatRow'], $rows);
    }

    public static function findGroupMessages(
        string $chatId,
        string $userId,
        ?string $after = null,
        ?string $before = null,
        int $limit = 50,
    ): array {
        $db = Database::getConnection();

        $conditions = [
            'm.chat_type = :chat_type',
            'm.chat_id = :chat_id',
            'EXISTS (SELECT 1 FROM ChatRoomMembers crm
                      WHERE crm.chat_room_id = m.chat_id
                        AND crm.user_id = :user_id)',
        ];
        $params = [
            ':chat_type' => 'group',
            ':chat_id'   => $chatId,
            ':user_id'   => $userId,
        ];

        if ($after !== null) {
            $conditions[] = 'm.created_at > :after';
            $params[':after'] = $after;
        }

        if ($before !== null) {
            $conditions[] = 'm.created_at < :before';
            $params[':before'] = $before;
        }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT m.id, m.user_id, m.chat_id, m.chat_type, m.direct_chat_id, m.body,
                       m.attachment_type, m.attachment_body, m.created_at
                FROM ChatMessages m WHERE {$where}
                ORDER BY m.created_at DESC
                LIMIT :limit";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return array_map([self::class, 'formatRow'], $rows);
    }

    public static function isGroupMember(string $chatId, string $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT 1 FROM ChatRoomMembers
              WHERE chat_room_id = :chat_id AND user_id = :user_id
              LIMIT 1'
        );
        $stmt->execute([
            ':chat_id' => $chatId,
            ':user_id' => $userId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public static function findByDirectChatId(
        string $directChatId,
        string $userId,
        ?string $after = null,
        ?string $before = null,
        int $limit = 50,
    ): array {
        $db = Database::getConnection();
        $idBytes = UuidV7::toBytes($directChatId);

        $conditions = [
            'm.direct_chat_id = :direct_chat_id',
            'EXISTS (SELECT 1 FROM direct_chats dc
                      WHERE dc.id = m.direct_chat_id
                        AND (dc.user_a_id = :user_id OR dc.user_b_id = :user_id))',
        ];
        $params = [
            ':direct_chat_id' => $idBytes,
            ':user_id' => $userId,
        ];

        if ($after !== null) {
            $conditions[] = 'm.created_at > :after';
            $params[':after'] = $after;
        }
        if ($before !== null) {
            $conditions[] = 'm.created_at < :before';
            $params[':before'] = $before;
        }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT m.id, m.user_id, m.chat_id, m.chat_type, m.direct_chat_id, m.body,
                       m.attachment_type, m.attachment_body, m.created_at
                FROM ChatMessages m WHERE {$where}
                ORDER BY m.created_at DESC
                LIMIT :limit";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return array_map([self::class, 'formatRow'], $rows);
    }

    public static function findByRoomId(
        string $roomId,
        string $userId,
        ?string $after = null,
        ?string $before = null,
        int $limit = 50,
    ): array {
        return self::findGroupMessages($roomId, $userId, $after, $before, $limit);
    }

    public static function findLatestByChatId(string $chatId, string $chatType, string $userId): ?array
    {
        $db = Database::getConnection();
        if ($chatType === 'direct') {
            $stmt = $db->prepare(
                "SELECT m.id, m.user_id, m.chat_id, m.chat_type, m.direct_chat_id, m.body,
                        m.attachment_type, m.attachment_body, m.created_at
                 FROM ChatMessages m
                 WHERE m.chat_type = 'direct'
                   AND (
                     (m.user_id = :user_id AND m.chat_id = :chat_id)
                     OR (m.user_id = :chat_id AND m.chat_id = :user_id)
                   )
                 ORDER BY m.created_at DESC LIMIT 1"
            );
            $stmt->execute([':user_id' => $userId, ':chat_id' => $chatId]);
        } else {
            $stmt = $db->prepare(
                "SELECT m.id, m.user_id, m.chat_id, m.chat_type, m.direct_chat_id, m.body,
                        m.attachment_type, m.attachment_body, m.created_at
                 FROM ChatMessages m
                 WHERE m.chat_type = 'group' AND m.chat_id = :chat_id
                   AND EXISTS (SELECT 1 FROM ChatRoomMembers crm
                                WHERE crm.chat_room_id = m.chat_id AND crm.user_id = :user_id)
                 ORDER BY m.created_at DESC LIMIT 1"
            );
            $stmt->execute([':user_id' => $userId, ':chat_id' => $chatId]);
        }
        $row = $stmt->fetch();
        return $row ? self::formatRow($row) : null;
    }

    public static function findLastByUserPerChat(string $userId, string $chatType): array
    {
        $db = Database::getConnection();
        if ($chatType === 'direct') {
            $stmt = $db->prepare(
                "SELECT
                    CASE WHEN m.user_id = :user_id THEN m.chat_id ELSE m.user_id END AS chat_id,
                    m.id, m.user_id, m.body, m.attachment_type, m.attachment_body,
                    m.created_at, m.direct_chat_id
                 FROM ChatMessages m
                 INNER JOIN (
                    SELECT
                        CASE WHEN user_id = :user_id THEN chat_id ELSE user_id END AS partner,
                        MAX(created_at) AS max_created
                    FROM ChatMessages
                    WHERE chat_type = 'direct'
                      AND (user_id = :user_id OR chat_id = :user_id)
                    GROUP BY partner
                 ) latest ON
                    ((m.user_id = :user_id AND m.chat_id = latest.partner) OR
                     (m.user_id = latest.partner AND m.chat_id = :user_id))
                    AND m.created_at = latest.max_created
                 WHERE m.chat_type = 'direct'"
            );
            $stmt->execute([':user_id' => $userId]);
        } else {
            $stmt = $db->prepare(
                "SELECT m.id, m.user_id, m.chat_id, m.body, m.attachment_type,
                        m.attachment_body, m.created_at
                 FROM ChatMessages m
                 INNER JOIN (
                    SELECT chat_id, MAX(created_at) AS max_created
                    FROM ChatMessages
                    WHERE chat_type = 'group'
                    GROUP BY chat_id
                 ) latest ON m.chat_id = latest.chat_id AND m.created_at = latest.max_created
                 WHERE m.chat_type = 'group'
                   AND EXISTS (SELECT 1 FROM ChatRoomMembers crm
                                WHERE crm.chat_room_id = m.chat_id AND crm.user_id = :user_id)"
            );
            $stmt->execute([':user_id' => $userId]);
        }
        $rows = $stmt->fetchAll();
        return array_map([self::class, 'formatRow'], $rows);
    }

    private static function formatRow(array $row): array
    {
        $attachment = null;
        if (!empty($row['attachment_body'])) {
            $attachment = [
                'type' => $row['attachment_type'] ?? 'image',
                'data' => $row['attachment_body'],
            ];
        }

        $result = [
            'id'              => bin2hex((string) $row['id']),
            'user_id'         => (string) $row['user_id'],
            'chat_id'         => (string) $row['chat_id'],
            'chat_type'       => (string) $row['chat_type'],
            'body'            => (string) $row['body'],
            'attachment'      => $attachment,
            'created_at'      => self::formatTimestamp($row['created_at']),
        ];

        if (!empty($row['direct_chat_id'])) {
            $result['direct_chat_id'] = bin2hex((string) $row['direct_chat_id']);
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
