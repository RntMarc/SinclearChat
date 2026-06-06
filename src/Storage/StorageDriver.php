<?php

declare(strict_types=1);

namespace SinclearChat\Storage;

interface StorageDriver
{
    public function put(string $path, string $binary, string $mime): void;

    public function get(string $path): string;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    public function getPublicUrl(string $path): string;
}
