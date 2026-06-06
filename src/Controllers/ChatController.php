<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\ChatRoomPermissions;
use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\DirectChat;
use SinclearChat\Models\Message;
use SinclearChat\Models\ReadReceipt;
use SinclearChat\Models\Room;
use SinclearChat\Models\SseEvent;
use SinclearChat\Models\UserProfile;
use SinclearChat\Response;

final class ChatController
{
    public static function list(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        try {
            $chats = [];

            $db = \SinclearChat\Database::getConnection();

            $stmt = $db->prepare(
                "SELECT r.id, r.name, r.description, r.avatar, r.ttl_days, r.created_at, r.updated_at
                 FROM ChatRooms r
                 INNER JOIN ChatRoomMembers m ON m.chat_room_id = r.id
                 WHERE m.user_id = :user_id
                 ORDER BY r.name ASC"
            );
            $stmt->execute([':user_id' => $userId]);
            $rooms = $stmt->fetchAll();

            foreach ($rooms as $room) {
                $roomId = (string) $room['id'];
                $latest = Message::findLatestByChatId($roomId, 'group', $userId);
                $unread = self::computeUnreadForGroup($userId, $roomId);
                $memberCount = (int) $db->query(
                    "SELECT COUNT(*) FROM ChatRoomMembers WHERE chat_room_id = " . $db->quote($roomId)
                )->fetchColumn();

                $chats[] = [
                    'id' => $roomId,
                    'type' => 'group',
                    'name' => (string) $room['name'],
                    'description' => $room['description'],
                    'avatar' => $room['avatar'],
                    'member_count' => $memberCount,
                    'last_message' => $latest ? [
                        'id' => $latest['id'],
                        'user_id' => $latest['user_id'],
                        'body' => $latest['body'],
                        'attachment' => $latest['attachment'],
                        'created_at' => $latest['created_at'],
                    ] : null,
                    'unread_count' => $unread,
                    'created_at' => (string) $room['created_at'],
                    'updated_at' => (string) $room['updated_at'],
                ];
            }

            $directChats = DirectChat::listForUser($userId);
            $partnerIds = [];
            foreach ($directChats as $dc) {
                $partnerIds[] = $dc['user_a_id'] === $userId ? $dc['user_b_id'] : $dc['user_a_id'];
            }
            $profiles = UserProfile::findByIds(array_values(array_unique($partnerIds)));

            foreach ($directChats as $dc) {
                $partnerId = $dc['user_a_id'] === $userId ? $dc['user_b_id'] : $dc['user_a_id'];
                $latest = Message::findLatestByChatId($partnerId, 'direct', $userId);
                $unread = self::computeUnreadForDirect($userId, $partnerId);
                $partner = $profiles[$partnerId] ?? null;

                $chats[] = [
                    'id' => $dc['id'],
                    'type' => 'direct',
                    'name' => $partner['display_name'] ?? $partnerId,
                    'avatar' => $partner['avatar'] ?? null,
                    'partner_id' => $partnerId,
                    'last_message' => $latest ? [
                        'id' => $latest['id'],
                        'user_id' => $latest['user_id'],
                        'body' => $latest['body'],
                        'attachment' => $latest['attachment'],
                        'created_at' => $latest['created_at'],
                    ] : null,
                    'unread_count' => $unread,
                    'created_at' => $dc['created_at'],
                    'updated_at' => $dc['last_message_at'] ?? $dc['created_at'],
                ];
            }

            usort($chats, static function (array $a, array $b): int {
                $aTime = $a['updated_at'] ?? $a['created_at'] ?? '';
                $bTime = $b['updated_at'] ?? $b['created_at'] ?? '';
                return strcmp((string) $bTime, (string) $aTime);
            });

            return Response::success(['data' => $chats]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to list chats: ' . $e->getMessage());
            return Response::error('Failed to list chats: ' . $e->getMessage(), 500);
        }
    }

    public static function show(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $chatId = $params['chatId'] ?? '';

        if ($chatId === '') {
            return Response::error('chatId is required');
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $chatId)) {
            $dc = DirectChat::findById($chatId);
            if ($dc === null) {
                return Response::notFound('Direct chat not found');
            }
            if (!DirectChat::isMember($chatId, $userId)) {
                return Response::forbidden('Not a member of this direct chat');
            }
            $partnerId = DirectChat::getPartnerId($chatId, $userId);
            $partner = $partnerId ? UserProfile::findById($partnerId) : null;
            return Response::success([
                'data' => [
                    'id' => $dc['id'],
                    'type' => 'direct',
                    'name' => $partner['display_name'] ?? $partnerId,
                    'avatar' => $partner['avatar'] ?? null,
                    'partner_id' => $partnerId,
                    'created_at' => $dc['created_at'],
                    'updated_at' => $dc['last_message_at'] ?? $dc['created_at'],
                ],
            ]);
        }

        $room = Room::findById($chatId);
        if ($room === null) {
            return Response::notFound('Chat room not found');
        }
        try {
            ChatRoomPermissions::ensureMember($chatId, $userId);
        } catch (\RuntimeException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success([
            'data' => [
                'id' => (string) $room['id'],
                'type' => 'group',
                'name' => (string) $room['name'],
                'description' => $room['description'],
                'avatar' => $room['avatar'],
                'ttl_days' => (int) $room['ttl_days'],
                'created_at' => (string) $room['created_at'],
                'updated_at' => (string) $room['updated_at'],
            ],
        ]);
    }

    public static function messages(array $params): Response
    {
        $chatId = $params['chatId'] ?? '';
        $chatType = $_GET['chat_type'] ?? null;
        if ($chatType === null && preg_match('/^[0-9a-f]{32}$/i', $chatId)) {
            $chatType = 'direct';
        } elseif ($chatType === null) {
            $chatType = 'group';
        }

        if (!in_array($chatType, ['direct', 'group'], true)) {
            return Response::error('chat_type must be "direct" or "group"');
        }

        $_GET['chat_id'] = $chatId;
        $_GET['chat_type'] = $chatType;

        return MessageV2Controller::pull();
    }

    public static function members(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $chatId = $params['chatId'] ?? '';

        if (preg_match('/^[0-9a-f]{32}$/i', $chatId)) {
            $dc = DirectChat::findById($chatId);
            if ($dc === null) {
                return Response::notFound('Direct chat not found');
            }
            if (!DirectChat::isMember($chatId, $userId)) {
                return Response::forbidden('Not a member of this direct chat');
            }
            return Response::success([
                'data' => [
                    ['user_id' => $dc['user_a_id'], 'role' => 'owner'],
                    ['user_id' => $dc['user_b_id'], 'role' => 'owner'],
                ],
            ]);
        }

        if (!ChatRoomPermissions::isMember($chatId, $userId)) {
            return Response::forbidden('Not a member of this chat room');
        }

        $members = Room::findMembersWithRoles($chatId);
        return Response::success(['data' => $members]);
    }

    public static function delete(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $chatId = $params['chatId'] ?? '';

        if (!preg_match('/^[0-9a-f]{32}$/i', $chatId)) {
            return Response::error('Only direct chats can be deleted via this endpoint');
        }
        if (!DirectChat::isMember($chatId, $userId)) {
            return Response::forbidden('Not a member of this direct chat');
        }
        if (!DirectChat::deleteForUser($chatId, $userId)) {
            return Response::error('Failed to delete direct chat', 500);
        }

        try {
            SseEvent::emit(null, 'chat_deleted', ['chat_id' => $chatId, 'user_id' => $userId]);
        } catch (\Throwable $e) {
            // best-effort
        }

        return new Response([], 204);
    }

    private static function computeUnreadForGroup(string $userId, string $roomId): int
    {
        $db = \SinclearChat\Database::getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM ChatMessages m
             LEFT JOIN chat_read_receipts rr
                ON rr.user_id = :user_id
                AND rr.chat_id = m.chat_id
                AND rr.chat_type = m.chat_type
             WHERE m.chat_type = 'group'
               AND m.chat_id = :room_id
               AND m.user_id != :user_id
               AND (rr.last_read_at IS NULL OR m.created_at > rr.last_read_at)"
        );
        $stmt->execute([':user_id' => $userId, ':room_id' => $roomId]);
        return (int) $stmt->fetchColumn();
    }

    private static function computeUnreadForDirect(string $userId, string $partnerId): int
    {
        $db = \SinclearChat\Database::getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM ChatMessages m
             LEFT JOIN chat_read_receipts rr
                ON rr.user_id = :user_id
                AND rr.chat_id = :partner_id
                AND rr.chat_type = 'direct'
             WHERE m.chat_type = 'direct'
               AND m.user_id = :partner_id
               AND m.chat_id = :user_id
               AND (rr.last_read_at IS NULL OR m.created_at > rr.last_read_at)"
        );
        $stmt->execute([':user_id' => $userId, ':partner_id' => $partnerId]);
        return (int) $stmt->fetchColumn();
    }
}
