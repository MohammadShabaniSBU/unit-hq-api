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
        public bool $replayed = false,
        public ?string $idempotencyKey = null,
        public ?string $resultType = null,
        public ?int $resultId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(
        array $data,
        string $display,
        FactBag $facts,
        ?HandoffReason $handoffReason = null,
        bool $replayed = false,
        ?string $idempotencyKey = null,
        ?string $resultType = null,
        ?int $resultId = null,
    ): self {
        if ($replayed) {
            $data['replayed'] = true;
        }

        return new self(
            ToolInvocationStatus::Ok,
            $data,
            $display,
            $facts,
            handoffReason: $handoffReason,
            replayed: $replayed,
            idempotencyKey: $idempotencyKey,
            resultType: $resultType,
            resultId: $resultId,
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

    public static function error(string $message, ?HandoffReason $handoffReason = null): self
    {
        return new self(
            ToolInvocationStatus::Error,
            [],
            '',
            new FactBag,
            message: $message,
            handoffReason: $handoffReason,
        );
    }

    public function withIdempotencyKey(string $key): self
    {
        return new self(
            $this->status,
            $this->data,
            $this->display,
            $this->facts,
            $this->deniedReason,
            $this->message,
            $this->handoffReason,
            $this->replayed,
            $key,
            $this->resultType,
            $this->resultId,
        );
    }
}
