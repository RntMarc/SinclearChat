<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;

final class Room
{
    public static function findAll(): array
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            'SELECT id, name, description, ttl_days, created_at, updated_at
             FROM ChatRooms
             ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }

    public static function findById(string $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT id, name, description, ttl_days, created_at, updated_at
             FROM ChatRooms WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findMembers(string $id): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT user_id FROM ChatRoomMembers WHERE chat_room_id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
