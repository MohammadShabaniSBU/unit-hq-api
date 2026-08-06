<?php

declare(strict_types=1);

namespace App\Support\Insights\Exceptions;

use RuntimeException;

/**
 * Provider discovery failure (machine reason for 409 responses).
 * Messages must never echo credential material (invariants 26 / 27).
 */
final class DiscoveryException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonKey,
        public readonly int $statusCode = 409,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reasonKey);
    }

    public static function unreachable(): self
    {
        return new self('provider_unreachable', 409);
    }

    public static function credentialsUnreadable(): self
    {
        return new self('credentials_unreadable', 409);
    }

    public static function notDiscoverable(): self
    {
        return new self('provider_not_discoverable', 409);
    }
}
