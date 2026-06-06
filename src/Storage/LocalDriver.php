<?php

declare(strict_types=1);

namespace SinclearChat\Storage;

use SinclearChat\Config;

final class LocalDriver implements StorageDriver
{
    private string $basePath;
    private string $publicUrl;

    public function __construct()
    {
        $config = Config::getInstance();
        $this->basePath = $config->get('STORAGE_LOCAL_PATH', dirname(__DIR__, 2) . '/storage');
        $this->publicUrl = rtrim((string) $config->get('STORAGE_LOCAL_PUBLIC_URL', '/files'), '/');

        if (!is_dir($this->basePath)) {
            @mkdir($this->basePath, 0755, true);
        }
    }

    public function put(string $path, string $binary, string $mime): void
    {
        $fullPath = $this->resolvePath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, $binary);
    }

    public function get(string $path): string
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }
        return $content;
    }

    public function delete(string $path): void
    {
        $fullPath = $this->resolvePath($path);
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolvePath($path));
    }

    public function getPublicUrl(string $path): string
    {
        return $this->publicUrl . '/' . ltrim($path, '/');
    }

    private function resolvePath(string $path): string
    {
        $safe = ltrim($path, '/');
        if (str_contains($safe, '..')) {
            throw new \RuntimeException("Invalid path: {$path}");
        }
        return $this->basePath . '/' . $safe;
    }
}
