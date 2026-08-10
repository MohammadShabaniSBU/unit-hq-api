<?php

declare(strict_types=1);

namespace App\Support\Ai\Results;

final readonly class AiVerificationResult
{
    /** @param  list<string>  $availableModels */
    private function __construct(
        public bool $ok,
        public ?string $error = null,
        public array $availableModels = [],
    ) {}

    /** @param  list<string>  $availableModels */
    public static function ok(array $availableModels): self
    {
        return new self(true, null, $availableModels);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
