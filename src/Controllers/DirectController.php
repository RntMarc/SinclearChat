<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\DirectChat;
use SinclearChat\Models\UserProfile;
use SinclearChat\Response;

final class DirectController
{
    public static function openOrCreate(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $otherUserId = trim((string) ($input['user_id'] ?? ''));
        if ($otherUserId === '') {
            return Response::error('Missing required field: user_id');
        }
        if ($otherUserId === $userId) {
            return Response::error('Cannot create a direct chat with yourself');
        }

        try {
            $chat = DirectChat::findOrCreate($userId, $otherUserId);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to create direct chat: ' . $e->getMessage());
            return Response::error('Failed to create direct chat: ' . $e->getMessage(), 500);
        }

        return Response::success([
            'chat_id' => $chat['id'],
            'partner_id' => $otherUserId,
            'created_at' => $chat['created_at'],
        ]);
    }

    public static function show(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $chatId = $params['chatId'] ?? '';

        if (!preg_match('/^[0-9a-f]{32}$/i', $chatId)) {
            return Response::error('Invalid chat_id (expected direct chat UUID)');
        }

        $chat = DirectChat::findById($chatId);
        if ($chat === null) {
            return Response::notFound('Direct chat not found');
        }
        if (!DirectChat::isMember($chatId, $userId)) {
            return Response::forbidden('Not a member of this direct chat');
        }

        $partnerId = DirectChat::getPartnerId($chatId, $userId);
        $partner = $partnerId ? UserProfile::findById($partnerId) : null;

        return Response::success([
            'data' => [
                'id' => $chat['id'],
                'type' => 'direct',
                'partner_id' => $partnerId,
                'partner' => $partner,
                'created_at' => $chat['created_at'],
                'updated_at' => $chat['last_message_at'] ?? $chat['created_at'],
            ],
        ]);
    }

    public static function list(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        try {
            $chats = DirectChat::listForUser($userId);
            $partnerIds = [];
            foreach ($chats as $chat) {
                $partnerIds[] = $chat['user_a_id'] === $userId ? $chat['user_b_id'] : $chat['user_a_id'];
            }
            $profiles = UserProfile::findByIds(array_values(array_unique($partnerIds)));

            $result = [];
            foreach ($chats as $chat) {
                $partnerId = $chat['user_a_id'] === $userId ? $chat['user_b_id'] : $chat['user_a_id'];
                $partner = $profiles[$partnerId] ?? null;
                $result[] = [
                    'id' => $chat['id'],
                    'partner_id' => $partnerId,
                    'partner' => $partner,
                    'created_at' => $chat['created_at'],
                    'updated_at' => $chat['last_message_at'] ?? $chat['created_at'],
                ];
            }
            return Response::success(['data' => $result]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to list direct chats: ' . $e->getMessage());
            return Response::error('Failed to list direct chats: ' . $e->getMessage(), 500);
        }
    }
}
