<?php

declare(strict_types=1);

namespace SinclearChat;

final class Config
{
    private static ?self $instance = null;
    private array $values = [];

    private function __construct()
    {
        $envFile = dirname(__DIR__) . '/.env';
        if (!file_exists($envFile)) {
            throw new \RuntimeException('.env file not found at ' . $envFile);
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, '"\'');
            $this->values[$key] = $value;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $_ENV[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->get($key, $default));
    }
}
