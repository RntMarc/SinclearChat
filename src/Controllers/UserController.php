<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\Http\NextJsClient;
use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\SseEvent;
use SinclearChat\Models\UserProfile;
use SinclearChat\Response;

final class UserController
{
    public static function me(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $profile = UserProfile::findById($userId);
        if ($profile === null) {
            $profile = self::fetchFromNextJs($userId);
        }
        if ($profile === null) {
            return Response::notFound('User profile not found');
        }

        return Response::success(['data' => $profile]);
    }

    public static function show(array $params): Response
    {
        TokenMiddleware::requireBearer();
        $userId = $params['id'] ?? '';

        $profile = UserProfile::findById($userId);
        if ($profile === null) {
            $profile = self::fetchFromNextJs($userId);
        }
        if ($profile === null) {
            return Response::notFound('User not found');
        }

        unset($profile['token_version']);
        return Response::success(['data' => $profile]);
    }

    public static function updateMe(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $displayName = isset($input['display_name']) ? trim((string) $input['display_name']) : null;
        $avatar = array_key_exists('avatar', $input) ? $input['avatar'] : null;
        $statusMessage = array_key_exists('status_message', $input) ? $input['status_message'] : null;

        if ($avatar !== null && !is_string($avatar)) {
            $avatar = null;
        }
        if ($statusMessage !== null && !is_string($statusMessage)) {
            $statusMessage = null;
        }

        try {
            UserProfile::upsert($userId, $displayName, $avatar, $statusMessage);
            try {
                SseEvent::emit(null, 'user_profile_updated', [
                    'user_id' => $userId,
                    'display_name' => $displayName,
                    'status_message' => $statusMessage,
                ]);
            } catch (\Throwable $e) {
                // best-effort
            }
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to update profile: ' . $e->getMessage());
            return Response::error('Failed to update profile: ' . $e->getMessage(), 500);
        }

        $profile = UserProfile::findById($userId);
        return Response::success(['data' => $profile]);
    }

    private static function fetchFromNextJs(string $userId): ?array
    {
        $profile = NextJsClient::fetchProfile($userId);
        if ($profile === null) {
            return null;
        }
        UserProfile::upsert(
            $userId,
            $profile['display_name'] ?? null,
            $profile['avatar'] ?? null,
            $profile['status_message'] ?? null,
        );
        return UserProfile::findById($userId);
    }
}
