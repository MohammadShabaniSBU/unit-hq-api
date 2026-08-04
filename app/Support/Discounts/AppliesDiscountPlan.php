<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Models\ContractItem;
use App\Models\Discount;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ResolvesContractItemPrice;
use App\Support\Contracts\WritesContractItemVersion;
use Illuminate\Validation\ValidationException;

/**
 * Materialize a VersionPlan onto the unit contract item inside the signing TX.
 * Window closing goes only through {@see WritesContractItemVersion}.
 */
final class AppliesDiscountPlan
{
    public static function apply(
        ContractItem $unitItem,
        Discount $discount,
        VersionPlan $plan,
        string $listAmount,
        ?int $createdBy = null,
    ): ContractItem {
        if ($unitItem->item_type !== 'unit') {
            throw ValidationException::withMessages([
                'discount_id' => ['Discounts apply to the unit item only.'],
            ]);
        }

        if ($discount->applies_to !== 'unit') {
            throw ValidationException::withMessages([
                'discount_id' => ['This discount does not apply to unit items.'],
            ]);
        }

        $list = BillingMath::round2($listAmount);
        $discountEndsAt = null;
        if (! $plan->noop && count($plan->segments) > 1) {
            foreach ($plan->segments as $segment) {
                if ($segment['to'] === null) {
                    $discountEndsAt = $segment['from'];
                    break;
                }
            }
        }

        $unitItem->forceFill([
            'discount_id' => $discount->id,
            'base_rate' => $list,
            'discount_ends_at' => $discountEndsAt,
        ])->save();

        if ($plan->noop || $plan->segments === []) {
            return $unitItem->load('price');
        }

        $unitItem->loadMissing(['price', 'item']);
        $subject = $unitItem->item;
        $siteId = $subject instanceof Unit ? (int) $subject->site_id : null;

        $first = $plan->segments[0];
        $firstPrice = ResolvesContractItemPrice::forSigning(
            'unit',
            (int) $unitItem->item_id,
            $first['amount'],
            $siteId,
            $createdBy,
            $unitItem->price,
        );

        $unitItem->forceFill([
            'price_id' => $firstPrice->id,
        ])->save();

        $current = $unitItem->fresh() ?? $unitItem;

        for ($i = 1; $i < count($plan->segments); $i++) {
            $segment = $plan->segments[$i];
            $result = WritesContractItemVersion::supersede(
                $current,
                $segment['amount'],
                $segment['from'],
                $siteId,
                $createdBy,
                null,
            );
            $current = $result['item'];
        }

        return $current->load('price');
    }
}
