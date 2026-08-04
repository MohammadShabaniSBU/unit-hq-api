<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Enums\ContractItemChangeReason;
use App\Models\ContractItem;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ResolvesContractItemPrice;
use Carbon\CarbonImmutable;

/**
 * Shared S02 window close + successor create. Used by ScheduleRateChange and
 * discount plan materialization — do not reimplement elsewhere (DISC-01 / DISC-02).
 */
final class WritesContractItemVersion
{
    public const PROVENANCE_CARRY = 'carry';

    public const PROVENANCE_CLEAR = 'clear';

    /**
     * Close the open item at $effectiveDate and create a successor at the new amount.
     *
     * @param  self::PROVENANCE_CARRY|self::PROVENANCE_CLEAR  $provenance
     * @return array{previous: ContractItem, item: ContractItem}
     */
    public static function supersede(
        ContractItem $openItem,
        string $newAmount,
        string $effectiveDate,
        ?int $siteId = null,
        ?int $createdBy = null,
        ?ContractItemChangeReason $changeReason = ContractItemChangeReason::RateChange,
        string $provenance = self::PROVENANCE_CARRY,
        ?string $baseRateOverride = null,
    ): array {
        $amount = BillingMath::round2($newAmount);
        $effective = CarbonImmutable::parse($effectiveDate)->startOfDay()->toDateString();

        $openItem->loadMissing(['price', 'item']);
        $subject = $openItem->item;
        $itemSiteId = $subject instanceof Unit
            ? (int) $subject->site_id
            : $siteId;

        $price = ResolvesContractItemPrice::forSigning(
            (string) $openItem->item_type,
            (int) $openItem->item_id,
            $amount,
            $itemSiteId,
            $createdBy,
            $openItem->price,
        );

        $openItem->forceFill([
            'effective_to' => $effective,
        ])->save();

        $discountId = null;
        $baseRate = null;
        $discountEndsAt = null;
        if ($provenance === self::PROVENANCE_CARRY) {
            $discountId = $openItem->discount_id;
            $baseRate = $baseRateOverride !== null
                ? BillingMath::round2($baseRateOverride)
                : $openItem->base_rate;
            $discountEndsAt = $openItem->discount_ends_at;
        }

        $successor = ContractItem::query()->create([
            'contract_id' => $openItem->contract_id,
            'item_type' => $openItem->item_type,
            'item_id' => $openItem->item_id,
            'price_id' => $price->id,
            'discount_id' => $discountId,
            'base_rate' => $baseRate,
            'discount_ends_at' => $discountEndsAt,
            'tax_rate_id' => $openItem->tax_rate_id,
            'tax_rate_snapshot' => $openItem->tax_rate_snapshot,
            'declared_goods_value' => $openItem->declared_goods_value,
            'description' => $openItem->description,
            'effective_from' => $effective,
            'effective_to' => null,
            'supersedes_id' => $openItem->id,
            'change_reason' => $changeReason,
        ]);

        return [
            'previous' => $openItem->fresh() ?? $openItem,
            'item' => $successor->load('price'),
        ];
    }

    /**
     * Zero-length future windows so itemsOn never sees them (removal collapse).
     *
     * @param  iterable<int, ContractItem>  $versions
     */
    public static function cancelFrom(iterable $versions, string $fromDate): void
    {
        $from = CarbonImmutable::parse($fromDate)->startOfDay()->toDateString();

        foreach ($versions as $version) {
            $versionFrom = CarbonImmutable::parse((string) $version->effective_from)
                ->startOfDay()
                ->toDateString();

            if ($versionFrom < $from) {
                continue;
            }

            $version->forceFill([
                'effective_to' => $versionFrom,
            ])->save();
        }
    }
}
