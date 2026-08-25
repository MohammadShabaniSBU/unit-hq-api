<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\AgentToolInvocation;
use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\ToolInvocationStatus;

/**
 * Whether this conversation already stated a catalogue amount for a class.
 * FactRegistry cannot answer this — availability licensing looks the same.
 */
final class PriorCatalogueQuote
{
    /** @var list<string> */
    private const TOOLS = ['pricing.quote', 'sales.propose_offer'];

    public static function namesClass(?AgentContext $ctx, int $unitClassId): bool
    {
        if ($ctx === null) {
            return false;
        }

        $invocations = $ctx->conversation->toolInvocations()
            ->where('status', ToolInvocationStatus::Ok->value)
            ->whereIn('tool_key', self::TOOLS)
            ->get();

        foreach ($invocations as $invocation) {
            if (self::names($invocation, $unitClassId)) {
                return true;
            }
        }

        return false;
    }

    private static function names(AgentToolInvocation $invocation, int $unitClassId): bool
    {
        $arguments = is_array($invocation->arguments) ? $invocation->arguments : [];
        if ((int) ($arguments['unit_class_id'] ?? 0) === $unitClassId) {
            return true;
        }

        $result = is_array($invocation->result) ? $invocation->result : [];
        if ((int) ($result['unit_class_id'] ?? 0) === $unitClassId) {
            return true;
        }

        return false;
    }
}
