<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\ChargeType;
use App\Enums\ProrationMethod;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Insurance;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ContractBilling;
use App\Support\Billing\FirstPeriodPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shared by ContractController::store and ReservationController::convert —
 * the only two places that actually write first-period charges — so a
 * generated charge never diverges from what convert-preview showed.
 *
 * Contract-level values (anchor/window/billed_through) are computed once by
 * the caller via ContractBilling::planFirstPeriod(); this trait fans that
 * plan out into one charge per item (tax/rate differ per line) and, once,
 * an optional deposit charge — never inside the per-item loop.
 */
trait GeneratesFirstPeriodCharges
{
    /**
     * @param  Collection<int, ContractItem>  $contractItems
     */
    protected function generateFirstPeriodCharges(
        Contract $contract,
        Collection $contractItems,
        FirstPeriodPlan $plan,
        ProrationMethod|string $prorationMethod,
        CarbonImmutable $moveIn,
    ): void {
        $method = $prorationMethod instanceof ProrationMethod ? $prorationMethod : ProrationMethod::from($prorationMethod);

        $skipFirstPeriodCharges = $plan->hasStub && $method === ProrationMethod::None;

        if (! $skipFirstPeriodCharges) {
            foreach ($contractItems as $item) {
                $item->loadMissing('price');
                $net = ContractBilling::firstPeriodNetForItem($plan, (string) $item->price->amount, $method);
                $rate = $item->tax_rate_snapshot !== null ? (string) $item->tax_rate_snapshot : null;
                $breakdown = BillingMath::applyTax($net, $rate);

                Charge::query()->create([
                    'contract_id'        => $contract->id,
                    'contract_item_id'   => $item->id,
                    'charge_type'        => $this->chargeTypeForItem($item->item_type),
                    'period_start'       => $plan->windowStart->toDateString(),
                    'period_end'         => $plan->windowEnd->toDateString(),
                    'net_amount'         => $breakdown->net,
                    'tax_rate_snapshot'  => $item->tax_rate_snapshot,
                    'tax_amount'         => $breakdown->tax,
                    'amount'             => $breakdown->gross,
                    'currency'           => $contract->currency,
                    'due_date'           => $moveIn->toDateString(),
                    'description'        => $this->chargeDescriptionForItem($item->item_type),
                ]);
            }

            $this->maybeCreateDepositCharge($contract, $moveIn);
        }

        $contract->forceFill([
            'billing_anchor_date' => $plan->anchorDate->toDateString(),
            'billed_through'      => $plan->billedThrough->toDateString(),
        ])->save();
    }

    private function maybeCreateDepositCharge(Contract $contract, CarbonImmutable $moveIn): void
    {
        if ((float) $contract->deposit_amount <= 0) {
            return;
        }

        Charge::query()->create([
            'contract_id'       => $contract->id,
            'contract_item_id'  => null,
            'charge_type'       => ChargeType::Deposit,
            'period_start'      => null,
            'period_end'        => null,
            'net_amount'        => $contract->deposit_amount,
            'tax_rate_snapshot' => null,
            'tax_amount'        => '0.00',
            'amount'            => $contract->deposit_amount,
            'currency'          => $contract->currency,
            'due_date'          => $moveIn->toDateString(),
            'description'       => 'Refundable deposit',
        ]);
    }

    private function chargeTypeForItem(string $itemType): ChargeType
    {
        return match ($itemType) {
            'unit'      => ChargeType::Rent,
            'insurance' => ChargeType::Insurance,
            default     => ChargeType::from($itemType),
        };
    }

    private function chargeDescriptionForItem(string $itemType): string
    {
        return match ($itemType) {
            'unit'      => 'Rent',
            'insurance' => 'Insurance',
            default     => ucfirst($itemType),
        };
    }

    /**
     * Resolution order: explicit override -> product's tax_rate_code active
     * version at $moveIn -> org default -> null (0%).
     */
    protected function resolveContractItemTaxRate(
        string $itemType,
        int $itemId,
        ?int $explicitTaxRateId,
        CarbonImmutable $moveIn,
    ): ?TaxRate {
        if ($explicitTaxRateId !== null) {
            return TaxRate::query()->find($explicitTaxRateId);
        }

        $productTaxRateCode = match ($itemType) {
            'unit'      => Unit::query()->with('unitClass')->find($itemId)?->unitClass?->tax_rate_code,
            'insurance' => Insurance::query()->find($itemId)?->tax_rate_code,
            default     => null,
        };

        return ContractBilling::resolveTaxRate($productTaxRateCode, $moveIn);
    }
}
