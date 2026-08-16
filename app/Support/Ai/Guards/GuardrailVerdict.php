<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\Enums\HandoffReason;

final readonly class GuardrailVerdict
{
    /**
     * @param  array<string, mixed>|null  $detail
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public bool $passed,
        public ?string $blockedBy = null,
        public ?HandoffReason $handoffReason = null,
        public ?array $detail = null,
        public ?string $mutatedDraft = null,
        public ?string $retry = null,
        public array $events = [],
        public ?string $subject = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public static function pass(?string $mutatedDraft = null, array $events = [], ?string $subject = null): self
    {
        return new self(true, mutatedDraft: $mutatedDraft, events: $events, subject: $subject);
    }

    /**
     * @param  array<string, mixed>|null  $detail
     * @param  list<array<string, mixed>>  $events
     */
    public static function block(string $blockedBy, HandoffReason $reason, ?array $detail = null, array $events = []): self
    {
        return new self(false, $blockedBy, $reason, $detail, events: $events);
    }

    /**
     * @param  array<string, mixed>|null  $detail
     * @param  list<array<string, mixed>>  $events
     */
    public static function retry(string $instruction, string $blockedBy, HandoffReason $reason, ?array $detail = null, array $events = []): self
    {
        return new self(false, $blockedBy, $reason, $detail, retry: $instruction, events: $events);
    }
}
