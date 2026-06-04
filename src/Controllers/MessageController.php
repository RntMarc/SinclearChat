<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\Response;
use SinclearChat\Models\Message;
use SinclearChat\Models\Room;

final class MessageController
{
    /** Maximum length (in bytes) for the attachment_body base64 string (Next.js MAX_FILE_SIZE_KB ≈ 546 KB base64 + margin). */
    public const MAX_ATTACHMENT_SIZE_BYTES = 600_000;
    public static function push(): Response
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            return Response::error('Empty request body');
        }

        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $userId = trim((string) ($input['user_id'] ?? ''));
        $chatId = trim((string) ($input['chat_id'] ?? ''));
        $chatType = trim((string) ($input['chat_type'] ?? ''));
        $body = (string) ($input['body'] ?? '');

        if ($userId === '' || $chatId === '' || $body === '') {
            return Response::error('Missing required fields: user_id, chat_id, body');
        }

        if (strlen($body) > 65000) {
            return Response::error('body too long (max 65000 characters)');
        }

        if (!in_array($chatType, ['direct', 'group'], true)) {
            return Response::error('chat_type must be "direct" or "group"');
        }

        if (strlen($userId) > 255 || strlen($chatId) > 255) {
            return Response::error('user_id and chat_id must not exceed 255 characters');
        }

        if ($chatType === 'direct' && $chatId === $userId) {
            return Response::error('chat_id must differ from user_id for direct messages');
        }

        if ($chatType === 'group') {
            $room = Room::findById($chatId);
            if ($room === null) {
                return Response::notFound('Chat room not found');
            }
            if (!Message::isGroupMember($chatId, $userId)) {
                return Response::error('You are not a member of this chat room', 403);
            }
        }

        $attachmentType = isset($input['attachment_type']) ? trim((string) $input['attachment_type']) : null;
        if ($attachmentType === '') {
            $attachmentType = null;
        }
        $attachmentBody = isset($input['attachment_body']) ? (string) $input['attachment_body'] : null;
        if ($attachmentBody === '') {
            $attachmentBody = null;
        }

        if ($attachmentBody !== null && strlen($attachmentBody) > self::MAX_ATTACHMENT_SIZE_BYTES) {
            return Response::error(
                'attachment_body too large (max ' . self::MAX_ATTACHMENT_SIZE_BYTES . ' bytes)'
            );
        }

        try {
            $message = Message::create($userId, $chatId, $chatType, $body, $attachmentType, $attachmentBody);
            return Response::created(['message' => $message]);
        } catch (\Throwable $e) {
            error_log("[SinclearChat] Failed to create message: " . $e->getMessage());
            return Response::error('Failed to create message: ' . $e->getMessage(), 500);
        }
    }

    public static function pull(): Response
    {
        $chatType = trim((string) ($_GET['chat_type'] ?? ''));
        $userId = trim((string) ($_GET['user_id'] ?? ''));
        $after = isset($_GET['after']) ? (string) $_GET['after'] : null;
        $before = isset($_GET['before']) ? (string) $_GET['before'] : null;
        $limit = (int) ($_GET['limit'] ?? 50);

        $config = \SinclearChat\Config::getInstance();
        $maxLimit = $config->getInt('MAX_MESSAGE_LIMIT', 200);
        $limit = min(max($limit, 1), $maxLimit);

        if (!in_array($chatType, ['direct', 'group'], true)) {
            return Response::error('chat_type must be "direct" or "group"');
        }

        if ($userId === '') {
            return Response::error('Missing required parameter: user_id');
        }

        if ($after !== null && !self::isValidTimestamp($after)) {
            return Response::error('Invalid "after" timestamp (expected ISO-8601)');
        }

        if ($before !== null && !self::isValidTimestamp($before)) {
            return Response::error('Invalid "before" timestamp (expected ISO-8601)');
        }

        try {
            if ($chatType === 'direct') {
                $chatPartnerId = trim((string) ($_GET['chat_partner_id'] ?? ''));
                if ($chatPartnerId === '') {
                    return Response::error('Missing required parameter: chat_partner_id for direct messages');
                }
                if ($chatPartnerId === $userId) {
                    return Response::error('chat_partner_id must differ from user_id');
                }

                $messages = Message::findDirectMessages($userId, $chatPartnerId, $after, $before, $limit + 1);
            } else {
                $chatId = trim((string) ($_GET['chat_id'] ?? ''));
                if ($chatId === '') {
                    return Response::error('Missing required parameter: chat_id for group messages');
                }

                $messages = Message::findGroupMessages($chatId, $userId, $after, $before, $limit + 1);
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
                'data'       => $messages,
                'pagination' => [
                    'has_more'    => $hasMore,
                    'next_before' => $nextBefore,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log("[SinclearChat] Failed to fetch messages: " . $e->getMessage());
            return Response::error('Failed to fetch messages: ' . $e->getMessage(), 500);
        }
    }

    private static function isValidTimestamp(string $value): bool
    {
        $ts = strtotime($value);
        return $ts !== false;
    }
}
