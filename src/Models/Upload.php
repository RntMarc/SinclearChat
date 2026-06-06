<?php

declare(strict_types=1);

namespace SinclearChat\Models;

use SinclearChat\Database;
use SinclearChat\Helper\UuidV7;
use SinclearChat\Storage\StorageFactory;

final class Upload
{
    public static function create(
        string $userId,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $binary,
    ): array {
        $config = \SinclearChat\Config::getInstance();
        $driverName = (string) $config->get('STORAGE_DRIVER', 'local');

        $id = UuidV7::generate();
        $idBytes = UuidV7::toBytes($id);
        $now = date('Y/m/d');
        $extension = self::extensionFromMime($mimeType);
        $safeName = pathinfo($filename, PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $safeName) ?: 'file';
        $storagePath = "{$now}/{$id}-{$safeName}.{$extension}";

        $storage = StorageFactory::getDriver();
        $storage->put($storagePath, $binary, $mimeType);

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO uploads
                (id, user_id, filename, mime_type, size_bytes, storage_path, storage_driver)
             VALUES
                (:id, :user_id, :filename, :mime_type, :size_bytes, :storage_path, :storage_driver)'
        );
        $stmt->execute([
            ':id' => $idBytes,
            ':user_id' => $userId,
            ':filename' => $filename,
            ':mime_type' => $mimeType,
            ':size_bytes' => $sizeBytes,
            ':storage_path' => $storagePath,
            ':storage_driver' => $driverName,
        ]);

        return [
            'id' => $id,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'url' => $storage->getPublicUrl($storagePath),
        ];
    }

    public static function findById(string $id): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/i', $id)) {
            return null;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT HEX(id) AS id, user_id, filename, mime_type, size_bytes, storage_path, storage_driver, created_at
             FROM uploads WHERE id = UNHEX(:id) LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'filename' => (string) $row['filename'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'storage_path' => (string) $row['storage_path'],
            'storage_driver' => (string) $row['storage_driver'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    public static function readBinary(array $upload): string
    {
        $storage = StorageFactory::getDriver();
        return $storage->get($upload['storage_path']);
    }

    private static function extensionFromMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/avif' => 'avif',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            default => 'bin',
        };
    }
}
