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
use SinclearChat\Models\Upload;
use SinclearChat\Response;

final class MessageV2Controller
{
    public const MAX_BODY_LENGTH = 65000;

    public static function push(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $chatId = trim((string) ($input['chat_id'] ?? ''));
        $chatType = trim((string) ($input['chat_type'] ?? ''));
        $body = (string) ($input['body'] ?? '');

        if ($chatId === '' || $body === '') {
            return Response::error('Missing required fields: chat_id, body');
        }
        if (strlen($body) > self::MAX_BODY_LENGTH) {
            return Response::error('body too long (max ' . self::MAX_BODY_LENGTH . ' characters)');
        }
        if (!in_array($chatType, ['direct', 'group'], true)) {
            return Response::error('chat_type must be "direct" or "group"');
        }

        $attachment = $input['attachment'] ?? null;
        $attachmentUploadId = null;
        $attachmentType = null;
        $attachmentBody = null;

        if (is_array($attachment)) {
            $type = (string) ($attachment['type'] ?? 'image');
            if (isset($attachment['upload_id'])) {
                $attachmentUploadId = (string) $attachment['upload_id'];
                $upload = Upload::findById($attachmentUploadId);
                if ($upload === null || $upload['user_id'] !== $userId) {
                    return Response::error('Invalid upload_id', 400);
                }
                $attachmentType = $type;
            } elseif (isset($attachment['data'])) {
                $attachmentBody = (string) $attachment['data'];
                $attachmentType = $type;
                if (strlen($attachmentBody) > MessageController::MAX_ATTACHMENT_SIZE_BYTES) {
                    return Response::error('attachment too large', 400);
                }
            }
        }

        $directChatId = null;

        if ($chatType === 'group') {
            $room = Room::findById($chatId);
            if ($room === null) {
                return Response::notFound('Chat room not found');
            }
            try {
                ChatRoomPermissions::ensureMember($chatId, $userId);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        } else {
            $partner = $chatId;
            if ($partner === $userId) {
                return Response::error('chat_id must differ from your user_id for direct messages');
            }
            $directChat = DirectChat::findOrCreate($userId, $partner);
            $directChatId = $directChat['id'];
            $chatId = $partner;
        }

        try {
            $message = Message::create(
                $userId,
                $chatId,
                $chatType,
                $body,
                $attachmentType,
                $attachmentBody,
                $attachmentUploadId,
                $directChatId,
            );
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to create message: ' . $e->getMessage());
            return Response::error('Failed to create message: ' . $e->getMessage(), 500);
        }

        if ($directChatId !== null) {
            DirectChat::updateLastMessageAt($directChatId);
        }

        try {
            SseEvent::emit(null, 'message', [
                'id' => $message['id'],
                'user_id' => $userId,
                'chat_id' => $chatType === 'direct' ? $directChatId : $chatId,
                'chat_type' => $chatType,
                'direct_chat_id' => $directChatId,
                'body' => $body,
                'attachment' => $message['attachment'],
                'created_at' => $message['created_at'],
            ]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to emit SSE event: ' . $e->getMessage());
        }

        return Response::created(['message' => $message]);
    }

    public static function pull(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $chatType = trim((string) ($_GET['chat_type'] ?? ''));
        $after = isset($_GET['after']) ? (string) $_GET['after'] : null;
        $before = isset($_GET['before']) ? (string) $_GET['before'] : null;
        $limit = (int) ($_GET['limit'] ?? 50);

        $config = \SinclearChat\Config::getInstance();
        $maxLimit = $config->getInt('MAX_MESSAGE_LIMIT', 200);
        $limit = min(max($limit, 1), $maxLimit);

        if (!in_array($chatType, ['direct', 'group'], true)) {
            return Response::error('chat_type must be "direct" or "group"');
        }

        if ($after !== null && !self::isValidTimestamp($after)) {
            return Response::error('Invalid "after" timestamp (expected ISO-8601)');
        }
        if ($before !== null && !self::isValidTimestamp($before)) {
            return Response::error('Invalid "before" timestamp (expected ISO-8601)');
        }

        try {
            if ($chatType === 'direct') {
                $chatId = trim((string) ($_GET['chat_id'] ?? ''));
                if ($chatId === '') {
                    return Response::error('Missing required parameter: chat_id for direct messages');
                }
                if (!DirectChat::isMember($chatId, $userId)) {
                    return Response::forbidden('Not a member of this direct chat');
                }
                $messages = Message::findByDirectChatId($chatId, $userId, $after, $before, $limit + 1);
            } else {
                $roomId = trim((string) ($_GET['chat_id'] ?? ''));
                if ($roomId === '') {
                    return Response::error('Missing required parameter: chat_id for group messages');
                }
                try {
                    ChatRoomPermissions::ensureMember($roomId, $userId);
                } catch (\RuntimeException $e) {
                    return Response::forbidden($e->getMessage());
                }
                $messages = Message::findGroupMessages($roomId, $userId, $after, $before, $limit + 1);
            }

            $hasMore = count($messages) > $limit;
            if ($hasMore) {
                array_pop($messages);
            }
            $nextBefore = null;
            if (!empty($messages)) {
                $nextBefore = end($messages)['created_at'];
            }

            return Response::success([
                'data' => $messages,
                'pagination' => [
                    'has_more' => $hasMore,
                    'next_before' => $nextBefore,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to fetch messages: ' . $e->getMessage());
            return Response::error('Failed to fetch messages: ' . $e->getMessage(), 500);
        }
    }

    public static function read(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $entries = [];

        if (isset($input['chat_id']) && isset($input['chat_type'])) {
            $chatId = trim((string) $input['chat_id']);
            $chatType = (string) $input['chat_type'];
            if (!in_array($chatType, ['direct', 'group'], true)) {
                return Response::error('Invalid chat_type');
            }
            if ($chatType === 'direct') {
                if (!DirectChat::isMember($chatId, $userId)) {
                    return Response::forbidden('Not a member of this direct chat');
                }
            } else {
                try {
                    ChatRoomPermissions::ensureMember($chatId, $userId);
                } catch (\RuntimeException $e) {
                    return Response::forbidden($e->getMessage());
                }
            }
            $entries[] = ['chat_id' => $chatId, 'chat_type' => $chatType];
        } elseif (isset($input['chats']) && is_array($input['chats'])) {
            foreach ($input['chats'] as $entry) {
                if (!is_array($entry) || !isset($entry['chat_id'], $entry['chat_type'])) {
                    return Response::error('Each entry needs chat_id and chat_type');
                }
                $cid = trim((string) $entry['chat_id']);
                $ctype = (string) $entry['chat_type'];
                if (!in_array($ctype, ['direct', 'group'], true)) {
                    return Response::error('Invalid chat_type in chats array');
                }
                $entries[] = ['chat_id' => $cid, 'chat_type' => $ctype];
            }
        } else {
            return Response::error('Provide chat_id + chat_type or chats array');
        }

        try {
            ReadReceipt::markMultipleRead($userId, $entries);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to mark as read: ' . $e->getMessage());
            return Response::error('Failed to mark as read: ' . $e->getMessage(), 500);
        }

        return new Response([], 204);
    }

    public static function unread(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        try {
            $counts = ReadReceipt::countUnreadForUser($userId);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to compute unread: ' . $e->getMessage());
            return Response::error('Failed to compute unread: ' . $e->getMessage(), 500);
        }

        return Response::success($counts);
    }

    private static function isValidTimestamp(string $value): bool
    {
        return strtotime($value) !== false;
    }
}
