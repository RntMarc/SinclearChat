<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\ChatRoomPermissions;
use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\DirectChat;
use SinclearChat\Models\SseEvent;
use SinclearChat\Response;

final class TypingController
{
    public static function set(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $chatId = trim((string) ($input['chat_id'] ?? ''));
        $chatType = (string) ($input['chat_type'] ?? '');
        $action = (string) ($input['action'] ?? '');

        if (!in_array($chatType, ['direct', 'group'], true)) {
            return Response::error('Invalid chat_type');
        }
        if (!in_array($action, ['start', 'stop'], true)) {
            return Response::error('Invalid action (start or stop)');
        }
        if ($chatId === '') {
            return Response::error('Missing chat_id');
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

        try {
            SseEvent::emit(null, 'typing', [
                'chat_id' => $chatId,
                'chat_type' => $chatType,
                'user_id' => $userId,
                'action' => $action,
            ]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to emit typing event: ' . $e->getMessage());
        }

        return new Response([], 204);
    }
}
