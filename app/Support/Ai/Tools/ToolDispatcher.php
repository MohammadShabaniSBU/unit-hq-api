<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\AgentWritePolicyGate;
use App\Support\Ai\Guards\CannedReply;
use LogicException;

final class ToolDispatcher
{
    /**
     * Pinned gate order. `dispatch()` walks this list. S25-01 fills provenance.
     *
     * @var list<string>
     */
    public const GATE_SEQUENCE = [
        'allowlist',
        'policy_off',
        'verification',
        'schema',
        'provenance',
        'ownership',
        'idempotency',
        'quota',
        'propose_or_handle',
    ];

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly AgentWritePolicyGate $writePolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function dispatch(
        AgentDefinition $definition,
        AgentPrincipal $principal,
        string $toolKey,
        array $arguments,
        ?AgentContext $ctx = null,
    ): ToolResult {
        $state = new ToolDispatchState(
            $definition,
            $principal,
            $toolKey,
            ArgumentBag::normalise($arguments),
            $ctx,
        );

        foreach (self::GATE_SEQUENCE as $gate) {
            $result = $this->runGate($gate, $state);
            if ($result !== null) {
                return $result;
            }
        }

        throw new LogicException('GATE_SEQUENCE did not produce a result.');
    }

    private function runGate(string $gate, ToolDispatchState $state): ?ToolResult
    {
        return match ($gate) {
            'allowlist' => $this->gateAllowlist($state),
            'policy_off' => $this->gatePolicyOff($state),
            'verification' => $this->gateVerification($state),
            'schema' => $this->gateSchema($state),
            'provenance' => $this->denyIfUnlicensed($state),
            'ownership' => $this->gateOwnership($state),
            'idempotency' => $this->gateIdempotency($state),
            'quota' => $this->gateQuota($state),
            'propose_or_handle' => $this->gateProposeOrHandle($state),
            default => throw new LogicException("Unknown dispatch gate [{$gate}]."),
        };
    }

    private function gateAllowlist(ToolDispatchState $state): ?ToolResult
    {
        if (! in_array($state->toolKey, $state->definition->toolKeys(), true)) {
            return ToolResult::denied(
                ToolDeniedReason::NotAllowedForAgent,
                "Tool [{$state->toolKey}] is not allowed for agent [{$state->definition->key()}].",
            );
        }

        if (! $this->registry->has($state->toolKey)) {
            return ToolResult::error("Tool [{$state->toolKey}] is not registered.");
        }

        $state->tool = $this->registry->get($state->toolKey);
        $state->policy = $state->ctx !== null
            ? $this->writePolicy->resolvePolicy($state->ctx, $state->toolKey)
            : null;

        return null;
    }

    private function gatePolicyOff(ToolDispatchState $state): ?ToolResult
    {
        return $this->writePolicy->denyIfOff($state->policy);
    }

    private function gateVerification(ToolDispatchState $state): ?ToolResult
    {
        $tool = $state->tool();
        $required = $this->writePolicy->effectiveVerification($tool, $state->policy);
        if ($state->principal->verification->satisfies($required)) {
            return null;
        }

        return ToolResult::denied(
            ToolDeniedReason::Verification,
            "Verification level [{$state->principal->verification->value}] does not satisfy [{$required->value}].",
        );
    }

    private function gateSchema(ToolDispatchState $state): ?ToolResult
    {
        $tool = $state->tool();
        $schemaError = $this->validateArguments($tool, $state->arguments);
        if ($schemaError !== null) {
            return ToolResult::fail(ToolError::invalidArguments($schemaError));
        }

        $state->arguments = $this->coerceArguments($tool, $state->arguments);

        return null;
    }

    /**
     * Argument provenance. S25-01 fills this; today it is a no-op so the slot exists.
     */
    private function denyIfUnlicensed(ToolDispatchState $state): ?ToolResult
    {
        return null;
    }

    private function gateOwnership(ToolDispatchState $state): ?ToolResult
    {
        $tool = $state->tool();
        foreach ($tool->contactScopedArgumentKeys() as $key) {
            $value = $state->arguments[$key] ?? null;
            if ($value === null || $value === '' || ! $state->principal->ownsContact((int) $value)) {
                return ToolResult::denied(
                    ToolDeniedReason::Ownership,
                    "Argument [{$key}] does not belong to this principal.",
                );
            }
        }

        return null;
    }

    private function gateIdempotency(ToolDispatchState $state): ?ToolResult
    {
        $tool = $state->tool();
        if ($state->ctx === null || ! $tool->isWrite()) {
            return null;
        }

        return $this->writePolicy->replay($state->ctx, $tool, $state->arguments);
    }

    private function gateQuota(ToolDispatchState $state): ?ToolResult
    {
        $tool = $state->tool();
        if ($state->ctx === null || ! $tool->isWrite()) {
            return null;
        }

        return $this->writePolicy->denyIfQuotaExceeded($state->ctx, $tool, $state->policy);
    }

    private function gateProposeOrHandle(ToolDispatchState $state): ToolResult
    {
        $tool = $state->tool();

        if ($this->writePolicy->isPropose($state->policy)) {
            return $this->dispatchPropose($tool, $state->principal, $state->arguments, $state->ctx);
        }

        $result = $tool->handle($state->principal, $state->arguments, $state->ctx);

        if (
            $state->ctx !== null
            && $tool->isWrite()
            && $result->status === ToolInvocationStatus::Ok
        ) {
            $result = $result->withIdempotencyKey(
                $this->writePolicy->idempotencyKey($state->ctx->conversation->id, $tool->key(), $state->arguments),
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function dispatchPropose(
        AgentTool $tool,
        AgentPrincipal $principal,
        array $arguments,
        ?AgentContext $ctx,
    ): ToolResult {
        if (! $tool instanceof ProposableTool) {
            throw new LogicException(
                "Write policy mode=propose requires ProposableTool; [{$tool->key()}] does not implement it.",
            );
        }

        $proposed = $tool->propose($principal, $arguments, $ctx);
        if ($proposed->status !== ToolInvocationStatus::Ok) {
            return $proposed;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($proposed->data['payload'] ?? null) ? $proposed->data['payload'] : [];
        /** @var array<string, mixed> $preview */
        $preview = is_array($proposed->data['preview'] ?? null) ? $proposed->data['preview'] : [];

        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : 0;
        if ($siteId <= 0) {
            return ToolResult::error('Site could not be resolved for this proposal.');
        }

        return ToolResult::requiresApproval(
            CannedReply::pendingApproval($principal->locale),
            $payload,
            $preview,
            $proposed->entities,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function coerceArguments(AgentTool $tool, array $arguments): array
    {
        $coerced = [];

        foreach ($tool->schema() as $key => $rules) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
                continue;
            }

            $type = $rules['type'] ?? 'string';
            $value = $this->coerceValue($arguments[$key], $type);

            if ($type === 'array' && is_array($value) && isset($rules['items']) && is_array($rules['items'])) {
                /** @var array<string, array<string, mixed>> $items */
                $items = $rules['items'];
                $value = $this->coerceArrayItems($value, $items);
            }

            $coerced[$key] = $value;
        }

        return $coerced;
    }

    /**
     * One level only: copy schema item keys and coerce scalars. Extra keys are dropped.
     *
     * @param  list<mixed>  $items
     * @param  array<string, array<string, mixed>>  $itemSchema
     * @return list<array<string, mixed>>
     */
    private function coerceArrayItems(array $items, array $itemSchema): array
    {
        $coerced = [];

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                $coerced[] = $item;

                continue;
            }

            $row = [];
            foreach ($itemSchema as $field => $rules) {
                if (! array_key_exists($field, $item) || $item[$field] === null || $item[$field] === '') {
                    continue;
                }

                $type = $rules['type'] ?? 'string';
                $row[$field] = $this->coerceValue($item[$field], $type);
            }
            $coerced[] = $row;
        }

        return $coerced;
    }

    private function coerceValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => is_int($value) ? $value : (int) $value,
            'number' => is_int($value) || is_float($value) ? $value : (float) $value,
            default => $value,
        };
    }

    private function validateArguments(AgentTool $tool, array $arguments): ?string
    {
        $contactScoped = $tool->contactScopedArgumentKeys();

        foreach ($tool->schema() as $key => $rules) {
            $required = (bool) ($rules['required'] ?? false);
            $present = array_key_exists($key, $arguments) && $arguments[$key] !== null && $arguments[$key] !== '';

            if ($required && ! $present) {
                if (in_array($key, $contactScoped, true)) {
                    continue;
                }

                return "Missing required argument [{$key}].";
            }

            if (! $present) {
                continue;
            }

            $type = $rules['type'] ?? 'string';
            if (! $this->valueMatchesType($arguments[$key], $type)) {
                return "Argument [{$key}] must be {$type}.";
            }

            if (isset($rules['enum']) && ! in_array($arguments[$key], $rules['enum'], true)) {
                return "Argument [{$key}] is not an allowed value.";
            }

            if ($type === 'array' && is_array($arguments[$key])) {
                $itemError = $this->validateArrayItems($key, $arguments[$key], $rules);
                if ($itemError !== null) {
                    return $itemError;
                }
            }
        }

        return null;
    }

    /**
     * One level only: min/max length and object-shaped item fields. No nested arrays.
     *
     * @param  list<mixed>  $items
     * @param  array<string, mixed>  $rules
     */
    private function validateArrayItems(string $key, array $items, array $rules): ?string
    {
        $count = count($items);
        $min = isset($rules['min']) ? (int) $rules['min'] : null;
        $max = isset($rules['max']) ? (int) $rules['max'] : null;

        if ($min !== null && $count < $min) {
            return "Argument [{$key}] must have at least {$min} item(s).";
        }

        if ($max !== null && $count > $max) {
            return "Argument [{$key}] must have at most {$max} item(s).";
        }

        if (! isset($rules['items']) || ! is_array($rules['items'])) {
            return null;
        }

        /** @var array<string, array<string, mixed>> $itemSchema */
        $itemSchema = $rules['items'];

        foreach ($items as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                return "Argument [{$key}][{$index}] must be an object.";
            }

            foreach ($itemSchema as $field => $fieldRules) {
                $required = (bool) ($fieldRules['required'] ?? false);
                $present = array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== '';

                if ($required && ! $present) {
                    return "Missing required argument [{$key}][{$index}].{$field}.";
                }

                if (! $present) {
                    continue;
                }

                $type = $fieldRules['type'] ?? 'string';
                if (! $this->valueMatchesType($item[$field], $type)) {
                    return "Argument [{$key}][{$index}].{$field} must be {$type}.";
                }

                if (isset($fieldRules['enum']) && ! in_array($item[$field], $fieldRules['enum'], true)) {
                    return "Argument [{$key}][{$index}].{$field} is not an allowed value.";
                }
            }
        }

        return null;
    }

    private function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            default => true,
        };
    }
}
