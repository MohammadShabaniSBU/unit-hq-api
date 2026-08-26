<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Price;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;

/**
 * Resolve catalogue quote continuity from the conversation trace.
 * Shared by sales.propose_offer and sales.create_offer — do not copy this path.
 */
final class QuoteContinuity
{
    public static function resolve(
        ?AgentContext $ctx,
        int $siteId,
        UnitClass $class,
        UnitClassRate $rate,
        ?int $suppliedPriceId,
    ): ContinuityDecision|ToolResult {
        $latest = PriorCatalogueQuote::latestFor($ctx, $siteId, $class->id);

        if ($latest !== null) {
            if ($suppliedPriceId !== null && $suppliedPriceId !== $latest['price_id']) {
                return ToolResult::fail(ToolError::invalidArguments(
                    'quoted_price_id does not match the price quoted in this conversation.',
                ));
            }

            return self::enforce($latest, $rate);
        }

        if ($suppliedPriceId !== null) {
            return ToolResult::fail(ToolError::invalidArguments(
                'quoted_price_id was supplied but this conversation has no catalogue quote for this class.',
            ));
        }

        return new ContinuityDecision(null, null, null);
    }

    public static function assertTax(ContinuityDecision $decision, ?int $currentTaxRateId): ?ToolResult
    {
        if ($decision->taxRateId === null) {
            return null;
        }

        if ($currentTaxRateId === $decision->taxRateId) {
            return null;
        }

        return ToolResult::fail(ToolError::priceSuperseded(
            'Tax rate for this class has been superseded.',
            [
                'superseded' => 'tax_rate',
                'quoted' => $decision->taxRateId,
                'current' => $currentTaxRateId,
            ],
        ));
    }

    /**
     * @param  array{price_id: int, tax_rate_id: int|null, invocation_id: int}  $latest
     */
    private static function enforce(array $latest, UnitClassRate $rate): ContinuityDecision|ToolResult
    {
        $quoted = Price::query()->find($latest['price_id']);
        if ($quoted === null) {
            return ToolResult::notFound('Quoted price not found.');
        }
        if ($quoted->priceable_type !== 'unit_class_rate' || (int) $quoted->priceable_id !== (int) $rate->id) {
            return ToolResult::fail(ToolError::invalidArguments(
                'quoted_price_id does not belong to this unit class rate.',
            ));
        }

        $current = $rate->price;
        if ($current === null || $current->id !== $latest['price_id']) {
            return ToolResult::fail(ToolError::priceSuperseded(
                'Catalogue price for this class has been superseded.',
                [
                    'superseded' => 'price',
                    'quoted' => $latest['price_id'],
                    'current' => $current?->id,
                ],
            ));
        }

        return new ContinuityDecision(
            $latest['price_id'],
            $latest['tax_rate_id'],
            $latest['invocation_id'],
        );
    }
}
