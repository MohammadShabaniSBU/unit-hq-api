<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Enums\ToolDeniedReason;

final class ToolDispatcher
{
    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function dispatch(
        AgentDefinition $definition,
        AgentPrincipal $principal,
        string $toolKey,
        array $arguments,
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

        if (! $principal->verification->satisfies($tool->requiredVerification())) {
            return ToolResult::denied(
                ToolDeniedReason::Verification,
                "Verification level [{$principal->verification->value}] does not satisfy [{$tool->requiredVerification()->value}].",
            );
        }

        $schemaError = $this->validateArguments($tool, $arguments);
        if ($schemaError !== null) {
            return ToolResult::error($schemaError);
        }

        foreach ($tool->contactScopedArgumentKeys() as $key) {
            $value = $arguments[$key] ?? null;
            if ($value === null || $value === '' || ! $principal->ownsContact((int) $value)) {
                return ToolResult::denied(
                    ToolDeniedReason::Ownership,
                    "Argument [{$key}] does not belong to this principal.",
                );
            }
        }

        return $tool->handle($principal, $arguments);
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
