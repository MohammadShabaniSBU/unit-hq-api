<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Destination key for a Vocal Bridge transfer. Never an E.164 or SIP value.
 */
final readonly class VoiceTransferResult
{
    public function __construct(
        public bool $transfer,
        public ?string $destination,
    ) {}

    public static function to(string $destination): self
    {
        return new self(true, $destination);
    }

    public static function failClosed(): self
    {
        return new self(false, null);
    }
}
