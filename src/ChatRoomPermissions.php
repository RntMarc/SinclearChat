<?php

declare(strict_types=1);

namespace SinclearChat;

use SinclearChat\Models\Room;

final class ChatRoomPermissions
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public static function getRole(string $chatRoomId, string $userId): ?string
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT role FROM ChatRoomMembers
             WHERE chat_room_id = :room_id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':room_id' => $chatRoomId, ':user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public static function isMember(string $chatRoomId, string $userId): bool
    {
        return self::getRole($chatRoomId, $userId) !== null;
    }

    public static function canRename(string $chatRoomId, string $userId): bool
    {
        $role = self::getRole($chatRoomId, $userId);
        return $role === self::ROLE_OWNER || $role === self::ROLE_ADMIN;
    }

    public static function canDelete(string $chatRoomId, string $userId): bool
    {
        return self::getRole($chatRoomId, $userId) === self::ROLE_OWNER;
    }

    public static function canManageMembers(string $chatRoomId, string $userId): bool
    {
        $role = self::getRole($chatRoomId, $userId);
        return $role === self::ROLE_OWNER || $role === self::ROLE_ADMIN;
    }

    public static function canRemoveMember(string $chatRoomId, string $actorId, string $targetId): bool
    {
        $actorRole = self::getRole($chatRoomId, $actorId);
        if ($actorRole === null) {
            return false;
        }
        if ($actorRole === self::ROLE_OWNER) {
            return true;
        }
        if ($actorRole === self::ROLE_ADMIN) {
            $targetRole = self::getRole($chatRoomId, $targetId);
            return $targetRole !== self::ROLE_OWNER && $targetRole !== self::ROLE_ADMIN;
        }
        return false;
    }

    public static function ensureMember(string $chatRoomId, string $userId): void
    {
        if (!self::isMember($chatRoomId, $userId)) {
            throw new \RuntimeException('Not a member of this chat room', 403);
        }
    }
}
