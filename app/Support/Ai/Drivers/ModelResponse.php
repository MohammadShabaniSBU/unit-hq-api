<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use Laravel\Ai\Responses\Data\Usage;

final readonly class ModelResponse
{
    /**
     * @param  list<array{name: string, id: string, arguments: array<string, mixed>}>  $toolCalls
     */
    public function __construct(
        public string $content,
        public array $toolCalls,
        public Usage $usage,
        public string $finishReason,
    ) {}
}
