<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;

final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<ForbiddenClaimKey>  $licensedClaims
     * @param  list<EntityRef>  $entities
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
        public array $licensedClaims = [],
        public array $entities = [],
        public ?ToolError $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<ForbiddenClaimKey>  $licensedClaims
     * @param  list<EntityRef>  $entities
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
        array $licensedClaims = [],
        array $entities = [],
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
            licensedClaims: $licensedClaims,
            entities: $entities,
        );
    }

    /**
     * @param  list<EntityRef>  $entities
     */
    public static function denied(
        ToolDeniedReason $reason,
        string $message,
        ?ToolError $error = null,
        array $entities = [],
    ): self {
        return new self(
            ToolInvocationStatus::Denied,
            [],
            $error?->summary() ?? self::deniedDisplay($reason),
            new FactBag,
            $reason,
            $message,
            entities: $entities,
            error: $error,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $preview
     * @param  list<EntityRef>  $entities
     */
    public static function requiresApproval(string $display, array $payload, array $preview, array $entities = []): self
    {
        return new self(
            ToolInvocationStatus::Denied,
            [
                'payload' => $payload,
                'preview' => $preview,
            ],
            $display,
            new FactBag,
            ToolDeniedReason::RequiresApproval,
            'requires_approval',
            entities: $entities,
        );
    }

    /**
     * @param  list<EntityRef>  $entities
     */
    public static function fail(
        ToolError $error,
        ToolInvocationStatus $status = ToolInvocationStatus::Error,
        ?HandoffReason $handoffReason = null,
        array $entities = [],
    ): self {
        return new self(
            $status,
            [],
            $error->summary(),
            new FactBag,
            message: $error->message,
            handoffReason: $handoffReason,
            entities: $entities,
            error: $error,
        );
    }

    /**
     * @param  list<EntityRef>  $entities
     */
    public static function notFound(string $message, array $entities = []): self
    {
        return self::fail(
            ToolError::notFound($message),
            ToolInvocationStatus::NotFound,
            entities: $entities,
        );
    }

    /**
     * @param  list<EntityRef>  $entities
     */
    public static function error(string $message, ?HandoffReason $handoffReason = null, array $entities = []): self
    {
        return self::fail(
            ToolError::unavailable($message),
            ToolInvocationStatus::Error,
            $handoffReason,
            $entities,
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
            $this->licensedClaims,
            $this->entities,
            $this->error,
        );
    }

    /**
     * Merge payload with reserved sibling keys for the invocation `result` column.
     *
     * @return array<string, mixed>|null
     */
    public function toTraceResult(): ?array
    {
        $blob = $this->data;
        unset($blob['entities'], $blob['error']);

        $blob['entities'] = array_map(
            static fn (EntityRef $ref): array => $ref->toArray(),
            $this->entities,
        );

        if ($this->error !== null) {
            $blob['error'] = $this->error->toArray();
        }

        if (
            $blob === ['entities' => []]
            && $this->error === null
            && $this->status !== ToolInvocationStatus::Ok
        ) {
            return null;
        }

        return $blob;
    }

    /**
     * Strip reserved keys so in-memory `data` never contains the registry.
     *
     * @param  array<string, mixed>|null  $blob
     * @return array<string, mixed>
     */
    public static function dataFromTrace(?array $blob): array
    {
        if ($blob === null) {
            return [];
        }

        unset($blob['entities'], $blob['error']);

        return $blob;
    }

    /**
     * @param  array<string, mixed>|null  $blob
     * @return list<EntityRef>
     */
    public static function entitiesFromTrace(?array $blob): array
    {
        if ($blob === null || ! isset($blob['entities']) || ! is_array($blob['entities'])) {
            return [];
        }

        $entities = [];
        foreach ($blob['entities'] as $row) {
            if (! is_array($row) || ! isset($row['type'], $row['id'], $row['label'])) {
                continue;
            }

            /** @var array{type: string, id: int, label: string, context?: string|null} $row */
            $entities[] = EntityRef::fromArray($row);
        }

        return $entities;
    }

    /**
     * @param  array<string, mixed>|null  $blob
     */
    public static function errorFromTrace(?array $blob): ?ToolError
    {
        if ($blob === null || ! isset($blob['error']) || ! is_array($blob['error'])) {
            return null;
        }

        if (! isset($blob['error']['code'], $blob['error']['message'])) {
            return null;
        }

        /** @var array{code: string, message: string, recovery?: array{tool: string, hint: string}|null, candidates?: list<array{type: string, id: int, label: string, context?: string|null}>, detail?: array<string, mixed>|null} $error */
        $error = $blob['error'];

        return ToolError::fromArray($error);
    }

    public static function deniedDisplay(ToolDeniedReason $reason): string
    {
        return match ($reason) {
            ToolDeniedReason::Verification => 'verification: this tool requires a verified contact',
            ToolDeniedReason::Ownership => 'ownership: this argument does not belong to this principal',
            ToolDeniedReason::NotAllowedForAgent => 'not_allowed_for_agent: this tool is not available',
            ToolDeniedReason::SiteScope => 'site_scope: this tool is not available at this site',
            ToolDeniedReason::QuotaExceeded => 'quota_exceeded: this tool has reached its write quota',
            ToolDeniedReason::RequiresApproval => 'requires_approval: waiting for operator approval',
            ToolDeniedReason::UnlicensedArgument => 'unlicensed_argument: this id was not returned by a tool, stated by the customer, or present in conversation context',
        };
    }
}
