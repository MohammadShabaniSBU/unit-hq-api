<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

/**
 * Outcome of POST /v1/users/:id/dial. Success is typically 204 with no call id.
 */
final readonly class DialResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public bool $ok,
        public ?string $aircallCallId,
        public ?string $error,
        public ?string $errorKey,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function success(?string $aircallCallId, array $raw = []): self
    {
        return new self(true, $aircallCallId, null, null, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(string $error, string $errorKey, array $raw = []): self
    {
        return new self(false, null, $error, $errorKey, $raw);
    }
}
