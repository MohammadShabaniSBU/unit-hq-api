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

    /**
     * Most recent ok quote of (site, class) in this conversation.
     *
     * Reads the result blob only: that is where the price lives. A failed-then-retried
     * call can name the class in arguments without ever quoting a price.
     *
     * @return array{price_id: int, tax_rate_id: int|null, invocation_id: int}|null
     */
    public static function latestFor(?AgentContext $ctx, int $siteId, int $unitClassId): ?array
    {
        if ($ctx === null) {
            return null;
        }

        $invocations = $ctx->conversation->toolInvocations()
            ->where('status', ToolInvocationStatus::Ok->value)
            ->whereIn('tool_key', self::TOOLS)
            ->orderByDesc('id')
            ->get();

        foreach ($invocations as $invocation) {
            $match = self::fromResult($invocation, $siteId, $unitClassId);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    public static function namesClass(?AgentContext $ctx, int $siteId, int $unitClassId): bool
    {
        return self::latestFor($ctx, $siteId, $unitClassId) !== null;
    }

    /**
     * @return array{price_id: int, tax_rate_id: int|null, invocation_id: int}|null
     */
    private static function fromResult(AgentToolInvocation $invocation, int $siteId, int $unitClassId): ?array
    {
        $result = is_array($invocation->result) ? $invocation->result : [];

        if ($invocation->tool_key === 'pricing.quote') {
            return self::pairFrom(
                $result,
                $siteId,
                $unitClassId,
                $invocation->id,
            );
        }

        $items = is_array($result['line_items'] ?? null) ? $result['line_items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $match = self::pairFrom($item, $siteId, $unitClassId, $invocation->id);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{price_id: int, tax_rate_id: int|null, invocation_id: int}|null
     */
    private static function pairFrom(array $row, int $siteId, int $unitClassId, int $invocationId): ?array
    {
        if ((int) ($row['site_id'] ?? 0) !== $siteId) {
            return null;
        }
        if ((int) ($row['unit_class_id'] ?? 0) !== $unitClassId) {
            return null;
        }
        if (! isset($row['price_id'])) {
            return null;
        }

        return [
            'price_id' => (int) $row['price_id'],
            'tax_rate_id' => isset($row['tax_rate_id']) ? (int) $row['tax_rate_id'] : null,
            'invocation_id' => $invocationId,
        ];
    }
}
