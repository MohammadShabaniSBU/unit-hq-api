<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;

final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public ToolInvocationStatus $status,
        public array $data,
        public string $display,
        public FactBag $facts,
        public ?ToolDeniedReason $deniedReason = null,
        public ?string $message = null,
        public ?HandoffReason $handoffReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(array $data, string $display, FactBag $facts, ?HandoffReason $handoffReason = null): self
    {
        return new self(
            ToolInvocationStatus::Ok,
            $data,
            $display,
            $facts,
            handoffReason: $handoffReason,
        );
    }

    public static function denied(ToolDeniedReason $reason, string $message): self
    {
        return new self(
            ToolInvocationStatus::Denied,
            [],
            '',
            new FactBag,
            $reason,
            $message,
        );
    }

    public static function notFound(string $message): self
    {
        return new self(
            ToolInvocationStatus::NotFound,
            [],
            '',
            new FactBag,
            message: $message,
        );
    }

    public static function error(string $message): self
    {
        return new self(
            ToolInvocationStatus::Error,
            [],
            '',
            new FactBag,
            message: $message,
        );
    }
}
