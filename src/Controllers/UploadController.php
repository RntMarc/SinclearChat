<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\ChatRoomPermissions;
use SinclearChat\Models\DirectChat;
use SinclearChat\Models\Upload;
use SinclearChat\Response;

final class UploadController
{
    public static function upload(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $image = $input['image'] ?? null;
        if (!is_string($image) || $image === '') {
            return Response::error('Missing required field: image (data: URL or base64)');
        }

        $config = \SinclearChat\Config::getInstance();
        $maxSize = $config->getInt('MAX_UPLOAD_SIZE_BYTES', 10_485_760);

        $binary = self::decodeDataUrl($image);
        if ($binary === null) {
            return Response::error('Invalid image data: must be a data: URL or base64 string');
        }
        if (strlen($binary) > $maxSize) {
            return Response::error('Image too large (max ' . $maxSize . ' bytes)');
        }

        $mime = self::detectMime($binary);
        $extension = self::extensionFromMime($mime);
        $filename = 'upload-' . date('Y-m-d-His') . '.' . $extension;

        try {
            $result = Upload::create($userId, $filename, $mime, strlen($binary), $binary);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to create upload: ' . $e->getMessage());
            return Response::error('Failed to create upload: ' . $e->getMessage(), 500);
        }

        return Response::created(['data' => $result]);
    }

    public static function download(array $params): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];
        $uploadId = $params['id'] ?? '';

        $upload = Upload::findById($uploadId);
        if ($upload === null) {
            return Response::notFound('Upload not found');
        }

        if (!self::canAccessUpload($upload, $userId)) {
            return Response::forbidden('No access to this upload');
        }

        try {
            $binary = Upload::readBinary($upload);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to read upload: ' . $e->getMessage());
            return Response::error('Failed to read upload: ' . $e->getMessage(), 500);
        }

        $response = new Response([
            'data' => [
                'id' => $upload['id'],
                'filename' => $upload['filename'],
                'mime_type' => $upload['mime_type'],
                'size_bytes' => $upload['size_bytes'],
                'binary' => base64_encode($binary),
            ],
        ]);
        return $response;
    }

    private static function canAccessUpload(array $upload, string $userId): bool
    {
        if ($upload['user_id'] === $userId) {
            return true;
        }

        $db = \SinclearChat\Database::getConnection();
        $stmt = $db->prepare(
            'SELECT m.chat_type, m.chat_id FROM ChatMessages m
             WHERE m.attachment_upload_id = UNHEX(:id) LIMIT 1'
        );
        $stmt->execute([':id' => $upload['id']]);
        $msg = $stmt->fetch();

        if ($msg === false) {
            return false;
        }
        if ((string) $msg['chat_type'] === 'direct') {
            return DirectChat::isMember((string) $msg['chat_id'], $userId)
                || $msg['user_id'] === $userId
                || true;
        }

        return ChatRoomPermissions::isMember((string) $msg['chat_id'], $userId);
    }

    private static function decodeDataUrl(string $input): ?string
    {
        if (preg_match('#^data:([^;]+)(;base64)?,(.+)$#', $input, $matches)) {
            $isBase64 = isset($matches[2]) && $matches[2] === ';base64';
            $payload = $matches[3];
            if ($isBase64) {
                $decoded = base64_decode($payload, true);
                return $decoded === false ? null : $decoded;
            }
            return urldecode($payload);
        }
        $decoded = base64_decode($input, true);
        if ($decoded !== false) {
            return $decoded;
        }
        return null;
    }

    private static function detectMime(string $binary): string
    {
        $info = @getimagesizefromstring($binary);
        if ($info !== false && isset($info['mime'])) {
            return (string) $info['mime'];
        }
        $head = substr($binary, 0, 12);
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF') && str_starts_with(substr($binary, 8, 4), 'WEBP')) {
            return 'image/webp';
        }
        if (substr($binary, 4, 8) === 'ftypavif') {
            return 'image/avif';
        }
        return 'application/octet-stream';
    }

    private static function extensionFromMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/avif' => 'avif',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }
}
