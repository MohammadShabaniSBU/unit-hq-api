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
        if (! in_array($toolKey, $definition->toolKeys(), true)) {
            return ToolResult::denied(
                ToolDeniedReason::NotAllowedForAgent,
                "Tool [{$toolKey}] is not allowed for agent [{$definition->key()}].",
            );
        }

        if (! $this->registry->has($toolKey)) {
            return ToolResult::error("Tool [{$toolKey}] is not registered.");
        }

        $tool = $this->registry->get($toolKey);
        $policy = $ctx !== null ? $this->writePolicy->resolvePolicy($ctx, $toolKey) : null;

        $off = $this->writePolicy->denyIfOff($policy);
        if ($off !== null) {
            return $off;
        }

        $required = $this->writePolicy->effectiveVerification($tool, $policy);
        if (! $principal->verification->satisfies($required)) {
            return ToolResult::denied(
                ToolDeniedReason::Verification,
                "Verification level [{$principal->verification->value}] does not satisfy [{$required->value}].",
            );
        }

        $schemaError = $this->validateArguments($tool, $arguments);
        if ($schemaError !== null) {
            return ToolResult::error($schemaError);
        }

        $arguments = $this->coerceArguments($tool, $arguments);

        foreach ($tool->contactScopedArgumentKeys() as $key) {
            $value = $arguments[$key] ?? null;
            if ($value === null || $value === '' || ! $principal->ownsContact((int) $value)) {
                return ToolResult::denied(
                    ToolDeniedReason::Ownership,
                    "Argument [{$key}] does not belong to this principal.",
                );
            }
        }

        if ($ctx !== null && $tool->isWrite()) {
            $replay = $this->writePolicy->replay($ctx, $tool, $arguments);
            if ($replay !== null) {
                return $replay;
            }

            $quota = $this->writePolicy->denyIfQuotaExceeded($ctx, $tool, $policy);
            if ($quota !== null) {
                return $quota;
            }
        }

        if ($this->writePolicy->isPropose($policy)) {
            return $this->dispatchPropose($tool, $principal, $arguments, $ctx);
        }

        $result = $tool->handle($principal, $arguments, $ctx);

        if (
            $ctx !== null
            && $tool->isWrite()
            && $result->status === ToolInvocationStatus::Ok
        ) {
            $result = $result->withIdempotencyKey(
                $this->writePolicy->idempotencyKey($ctx->conversation->id, $tool->key(), $arguments),
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
