<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\Presence;
use SinclearChat\Models\SseEvent;
use SinclearChat\Response;

final class PresenceController
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

        $status = trim((string) ($input['status'] ?? ''));
        if (!in_array($status, Presence::ALLOWED_STATUSES, true)) {
            return Response::error('Invalid status (allowed: ' . implode(', ', Presence::ALLOWED_STATUSES) . ')');
        }

        try {
            Presence::setStatus($userId, $status);
            SseEvent::emit(null, 'presence', [
                'user_id' => $userId,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to set presence: ' . $e->getMessage());
            return Response::error('Failed to set presence: ' . $e->getMessage(), 500);
        }

        return Response::success(['data' => ['status' => $status]]);
    }

    public static function get(array $params): Response
    {
        TokenMiddleware::requireBearer();
        $userId = $params['userId'] ?? '';

        if ($userId === '') {
            return Response::error('userId is required');
        }

        try {
            $data = Presence::getStatus($userId);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to get presence: ' . $e->getMessage());
            return Response::error('Failed to get presence: ' . $e->getMessage(), 500);
        }

        return Response::success(['data' => $data]);
    }
}
