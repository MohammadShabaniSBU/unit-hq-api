<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Request-scoped correlation id. Set once per HTTP request or queued job; read anywhere.
 */
final class RequestId
{
    private static ?string $id = null;

    public static function set(string $id): void
    {
        self::$id = $id;
    }

    public static function get(): ?string
    {
        return self::$id;
    }

    public static function clear(): void
    {
        self::$id = null;
    }
}
