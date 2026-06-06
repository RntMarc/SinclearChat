<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\ChatRoomPermissions;
use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\Room;
use SinclearChat\Models\SseEvent;
use SinclearChat\Response;

final class GroupController
{
    public static function create(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $name = trim((string) ($input['name'] ?? ''));
        $description = isset($input['description']) ? (string) $input['description'] : null;
        $avatar = isset($input['avatar']) ? (string) $input['avatar'] : null;
        $memberIds = $input['member_ids'] ?? [];
        $ttlDays = isset($input['ttl_days']) ? max(1, (int) $input['ttl_days']) : 30;

        if ($name === '') {
            return Response::error('Missing required field: name');
        }
        if (!is_array($memberIds)) {
            return Response::error('member_ids must be an array');
        }

        $id = self::generateRoomId($name);
        $ownerId = $userId;

        try {
            Room::create($id, $name, $description, $avatar, $ttlDays, $ownerId);

            foreach ($memberIds as $memberId) {
                $mid = trim((string) $memberId);
                if ($mid === '' || $mid === $ownerId) {
                    continue;
                }
                Room::addMember($id, $mid, 'member');
            }
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to create group: ' . $e->getMessage());
            return Response::error('Failed to create group: ' . $e->getMessage(), 500);
        }

        try {
            SseEvent::emit(null, 'group_created', ['group_id' => $id, 'name' => $name, 'created_by' => $ownerId]);
        } catch (\Throwable $e) {
            // best-effort
        }

        $room = Room::findById($id);
        return Response::created(['data' => self::formatRoom($room)]);
    }

    public static function show(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $id = $params['id'] ?? '';

        $room = Room::findById($id);
        if ($room === null) {
            return Response::notFound('Group not found');
        }
        try {
            ChatRoomPermissions::ensureMember($id, $userId);
        } catch (\RuntimeException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success(['data' => self::formatRoom($room)]);
    }

    public static function update(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $id = $params['id'] ?? '';

        if (!ChatRoomPermissions::canRename($id, $userId)) {
            return Response::forbidden('Insufficient permissions');
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $name = isset($input['name']) ? trim((string) $input['name']) : null;
        $description = isset($input['description']) ? (string) $input['description'] : null;
        $avatar = array_key_exists('avatar', $input) ? $input['avatar'] : null;
        if ($avatar !== null) {
            $avatar = is_string($avatar) ? $avatar : null;
        }

        try {
            Room::update($id, $name, $description, $avatar);
            SseEvent::emit(null, 'group_updated', ['group_id' => $id, 'name' => $name]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to update group: ' . $e->getMessage());
            return Response::error('Failed to update group: ' . $e->getMessage(), 500);
        }

        $room = Room::findById($id);
        return Response::success(['data' => self::formatRoom($room)]);
    }

    public static function delete(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $id = $params['id'] ?? '';

        if (!ChatRoomPermissions::canDelete($id, $userId)) {
            return Response::forbidden('Only the owner can delete the group');
        }

        try {
            Room::delete($id);
            SseEvent::emit(null, 'group_deleted', ['group_id' => $id]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to delete group: ' . $e->getMessage());
            return Response::error('Failed to delete group: ' . $e->getMessage(), 500);
        }

        return new Response([], 204);
    }

    public static function leave(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $id = $params['id'] ?? '';

        $role = ChatRoomPermissions::getRole($id, $userId);
        if ($role === null) {
            return Response::forbidden('Not a member of this group');
        }
        if ($role === ChatRoomPermissions::ROLE_OWNER) {
            return Response::error('Owner must transfer ownership before leaving', 409);
        }

        try {
            Room::removeMember($id, $userId);
            SseEvent::emit(null, 'group_left', ['group_id' => $id, 'user_id' => $userId]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to leave group: ' . $e->getMessage());
            return Response::error('Failed to leave group: ' . $e->getMessage(), 500);
        }

        return new Response([], 204);
    }

    public static function addMember(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $id = $params['id'] ?? '';

        if (!ChatRoomPermissions::canManageMembers($id, $userId)) {
            return Response::forbidden('Insufficient permissions');
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $newUserId = trim((string) ($input['user_id'] ?? ''));
        $role = (string) ($input['role'] ?? 'member');
        if (!in_array($role, ['member', 'admin'], true)) {
            return Response::error('Invalid role (member or admin)');
        }
        if ($newUserId === '') {
            return Response::error('Missing required field: user_id');
        }

        try {
            Room::addMember($id, $newUserId, $role);
            SseEvent::emit(null, 'group_member_added', ['group_id' => $id, 'user_id' => $newUserId, 'role' => $role]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to add member: ' . $e->getMessage());
            return Response::error('Failed to add member: ' . $e->getMessage(), 500);
        }

        return Response::success(['data' => ['group_id' => $id, 'user_id' => $newUserId, 'role' => $role]]);
    }

    public static function removeMember(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $id = $params['id'] ?? '';
        $targetId = $params['userId'] ?? '';

        if (!ChatRoomPermissions::canRemoveMember($id, $userId, $targetId)) {
            return Response::forbidden('Insufficient permissions');
        }

        try {
            Room::removeMember($id, $targetId);
            SseEvent::emit(null, 'group_member_removed', ['group_id' => $id, 'user_id' => $targetId]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to remove member: ' . $e->getMessage());
            return Response::error('Failed to remove member: ' . $e->getMessage(), 500);
        }

        return new Response([], 204);
    }

    private static function formatRoom(?array $room): array
    {
        if ($room === null) {
            return [];
        }
        return [
            'id' => (string) $room['id'],
            'type' => 'group',
            'name' => (string) $room['name'],
            'description' => $room['description'],
            'avatar' => $room['avatar'],
            'ttl_days' => (int) $room['ttl_days'],
            'created_at' => (string) $room['created_at'],
            'updated_at' => (string) $room['updated_at'],
        ];
    }

    private static function generateRoomId(string $name): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? 'group');
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'group';
        }
        $base = substr($base, 0, 32);
        $suffix = bin2hex(random_bytes(4));
        return "{$base}-{$suffix}";
    }
}
