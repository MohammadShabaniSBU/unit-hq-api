<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Discount;
use Illuminate\Validation\ValidationException;

/**
 * Shared validation + compile/apply for signing surfaces (DISC-01).
 */
final class AttachesDiscount
{
    public static function assertAttachable(Discount $discount, Contract $contract): void
    {
        if ($discount->isArchived()) {
            throw ValidationException::withMessages([
                'discount_id' => ['Archived discounts cannot be attached.'],
            ]);
        }

        if ($discount->applies_to !== 'unit') {
            throw ValidationException::withMessages([
                'discount_id' => ['This discount does not apply to unit items.'],
            ]);
        }

        $already = $contract->items()
            ->whereNotNull('discount_id')
            ->exists();

        if ($already) {
            throw ValidationException::withMessages([
                'discount_id' => ['Only one discount may be attached per contract.'],
            ]);
        }
    }

    public static function compileAndApply(
        Contract $contract,
        Discount $discount,
        string $listAmount,
        string $currency,
        string $anchorDate,
        ?int $commitmentWeeks,
        ?int $createdBy = null,
    ): VersionPlan {
        self::assertAttachable($discount, $contract);

        /** @var ContractItem|null $unitItem */
        $unitItem = $contract->items()
            ->where('item_type', 'unit')
            ->whereNull('effective_to')
            ->first();

        if ($unitItem === null) {
            throw ValidationException::withMessages([
                'discount_id' => ['A unit item is required to attach a discount.'],
            ]);
        }

        $interval = $contract->billing_interval;
        $intervalValue = $interval instanceof \BackedEnum ? $interval->value : (string) $interval;

        $ctx = new CompileContext(
            listAmount: $listAmount,
            currency: $currency,
            interval: $intervalValue,
            intervalCount: (int) $contract->billing_interval_count,
            anchorDate: $anchorDate,
            commitmentWeeks: $commitmentWeeks,
        );

        $plan = DiscountCompiler::compile($discount, $ctx);
        AppliesDiscountPlan::apply($unitItem, $discount, $plan, $listAmount, $createdBy);

        return $plan;
    }

    public static function previewPlan(
        Discount $discount,
        string $listAmount,
        string $currency,
        string $interval,
        int $intervalCount,
        string $anchorDate,
        ?int $commitmentWeeks,
    ): VersionPlan {
        if ($discount->isArchived()) {
            throw ValidationException::withMessages([
                'discount_id' => ['Archived discounts cannot be attached.'],
            ]);
        }

        if ($discount->applies_to !== 'unit') {
            throw ValidationException::withMessages([
                'discount_id' => ['This discount does not apply to unit items.'],
            ]);
        }

        $ctx = new CompileContext(
            listAmount: $listAmount,
            currency: $currency,
            interval: $interval,
            intervalCount: $intervalCount,
            anchorDate: $anchorDate,
            commitmentWeeks: $commitmentWeeks,
        );

        return DiscountCompiler::compile($discount, $ctx);
    }
}
