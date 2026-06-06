<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\Device;
use SinclearChat\Response;

final class DeviceController
{
    public static function register(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $platform = trim((string) ($input['platform'] ?? ''));
        $deviceId = trim((string) ($input['device_id'] ?? ''));
        $pushToken = (string) ($input['push_token'] ?? '');
        $appVersion = isset($input['app_version']) ? (string) $input['app_version'] : null;

        if ($platform === '' || $deviceId === '' || $pushToken === '') {
            return Response::error('Missing required fields: platform, device_id, push_token');
        }

        if (!in_array($platform, ['android', 'ios', 'linux', 'windows', 'web', 'macos'], true)) {
            return Response::error('Invalid platform');
        }

        try {
            $id = Device::register($userId, $platform, $deviceId, $pushToken, $appVersion);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to register device: ' . $e->getMessage());
            return Response::error('Failed to register device: ' . $e->getMessage(), 500);
        }

        return Response::created(['data' => ['id' => $id]]);
    }

    public static function list(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        try {
            $devices = Device::listForUser($userId);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to list devices: ' . $e->getMessage());
            return Response::error('Failed to list devices: ' . $e->getMessage(), 500);
        }

        return Response::success(['data' => $devices]);
    }

    public static function delete(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $deviceId = $params['id'] ?? '';

        if ($deviceId === '') {
            return Response::error('device id is required');
        }

        try {
            $ok = Device::delete($deviceId, $userId);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to delete device: ' . $e->getMessage());
            return Response::error('Failed to delete device: ' . $e->getMessage(), 500);
        }

        if (!$ok) {
            return Response::notFound('Device not found');
        }
        return new Response([], 204);
    }
}
