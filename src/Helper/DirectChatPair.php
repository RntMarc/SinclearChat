<?php

declare(strict_types=1);

namespace SinclearChat\Helper;

final class DirectChatPair
{
    public static function normalize(string $userA, string $userB): array
    {
        if ($userA === $userB) {
            throw new \InvalidArgumentException('Cannot create a direct chat with the same user');
        }

        $compare = strcmp($userA, $userB);
        if ($compare < 0) {
            return [$userA, $userB];
        }
        return [$userB, $userA];
    }

    public static function fromArray(array $userIds): array
    {
        if (count($userIds) !== 2) {
            throw new \InvalidArgumentException('Direct chat requires exactly 2 user IDs');
        }
        return self::normalize((string) $userIds[0], (string) $userIds[1]);
    }
}
