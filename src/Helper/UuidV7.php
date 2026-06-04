<?php

declare(strict_types=1);

namespace SinclearChat\Helper;

final class UuidV7
{
    public static function generate(): string
    {
        $time = (int) (microtime(true) * 1000);
        $timeBytes = pack('J', $time);

        $random = random_bytes(10);

        $bytes = substr($timeBytes, 2, 6) . $random;

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return bin2hex($bytes);
    }

    public static function toBytes(string $hex): string
    {
        return hex2bin($hex);
    }
}
