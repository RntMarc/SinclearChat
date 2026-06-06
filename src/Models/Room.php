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
            'SELECT id, name, description, avatar, ttl_days, created_at, updated_at
             FROM ChatRooms
             ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }

    public static function findById(string $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT id, name, description, avatar, ttl_days, created_at, updated_at
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

    public static function create(
        string $id,
        string $name,
        ?string $description,
        ?string $avatar,
        int $ttlDays,
        string $ownerId,
    ): void {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO ChatRooms (id, name, description, avatar, ttl_days)
                 VALUES (:id, :name, :description, :avatar, :ttl_days)'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':description' => $description,
                ':avatar' => $avatar,
                ':ttl_days' => $ttlDays,
            ]);

            $stmt = $db->prepare(
                'INSERT INTO ChatRoomMembers (chat_room_id, user_id, role)
                 VALUES (:room_id, :user_id, :role)'
            );
            $stmt->execute([
                ':room_id' => $id,
                ':user_id' => $ownerId,
                ':role' => 'owner',
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function update(string $id, ?string $name, ?string $description, ?string $avatar): void
    {
        $sets = [];
        $params = [':id' => $id];
        if ($name !== null) {
            $sets[] = 'name = :name';
            $params[':name'] = $name;
        }
        if ($description !== null) {
            $sets[] = 'description = :description';
            $params[':description'] = $description;
        }
        if ($avatar !== null) {
            $sets[] = 'avatar = :avatar';
            $params[':avatar'] = $avatar;
        }
        if (empty($sets)) {
            return;
        }
        $db = Database::getConnection();
        $sql = 'UPDATE ChatRooms SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $db->prepare($sql)->execute($params);
    }

    public static function delete(string $id): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM ChatRooms WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function addMember(string $roomId, string $userId, string $role = 'member'): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT IGNORE INTO ChatRoomMembers (chat_room_id, user_id, role)
             VALUES (:room_id, :user_id, :role)'
        );
        $stmt->execute([
            ':room_id' => $roomId,
            ':user_id' => $userId,
            ':role' => $role,
        ]);
    }

    public static function removeMember(string $roomId, string $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'DELETE FROM ChatRoomMembers WHERE chat_room_id = :room_id AND user_id = :user_id'
        );
        $stmt->execute([':room_id' => $roomId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function findMembersWithRoles(string $id): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT user_id, role, joined_at FROM ChatRoomMembers WHERE chat_room_id = :id ORDER BY joined_at ASC'
        );
        $stmt->execute([':id' => $id]);
        return array_map(static function (array $row): array {
            return [
                'user_id' => (string) $row['user_id'],
                'role' => (string) $row['role'],
                'joined_at' => (string) $row['joined_at'],
            ];
        }, $stmt->fetchAll());
    }
}
