<?php

declare(strict_types=1);

namespace SinclearChat\Storage;

use SinclearChat\Config;

final class StorageFactory
{
    private static ?StorageDriver $instance = null;

    public static function getDriver(): StorageDriver
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = Config::getInstance();
        $driver = (string) $config->get('STORAGE_DRIVER', 'local');

        return self::$instance = match ($driver) {
            'local' => new LocalDriver(),
            default => throw new \RuntimeException("Unknown storage driver: {$driver}"),
        };
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
