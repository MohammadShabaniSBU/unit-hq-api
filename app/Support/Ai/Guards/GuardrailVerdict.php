<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\Enums\HandoffReason;

final readonly class GuardrailVerdict
{
    public function __construct(
        public bool $passed,
        public ?string $blockedBy = null,
        public ?HandoffReason $handoffReason = null,
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function block(string $blockedBy, HandoffReason $reason): self
    {
        return new self(false, $blockedBy, $reason);
    }
}
