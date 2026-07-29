<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

final readonly class VerificationResult
{
    private function __construct(
        public bool $ok,
        public ?string $error = null,
    ) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
