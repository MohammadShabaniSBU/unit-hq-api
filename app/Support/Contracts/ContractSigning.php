<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Enums\ChargeType;
use App\Enums\ContractEndedReason;
use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Enums\ProrationMethod;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ContractBilling;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\FirstPeriodPlan;
use App\Support\ESign\EnvelopeOrchestrator;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\Occupancy\HoldGuard;
use App\Support\Occupancy\OccupancyGuard;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Sole implementation of "contract becoming signed" (invariant 20 amended).
 * Walk-in create and remote signature completion both call complete() inside
 * the caller's transaction — charges, first invoice, and occupancy open here.
 */
final class ContractSigning
{
    /**
     * Open occupancies, write first-period charges + invoice, set signed_at,
     * transition awaiting → pending|active, log contract.signed.
     *
     * @throws ValidationException
     */
    public static function complete(
        Contract $contract,
        ?CarbonImmutable $endedOn = null,
        ?int $createdBy = null,
        CarbonInterface|string|null $signedAt = null,
    ): void {
        $contract->loadMissing([
            'items.price',
            'contact',
            'unitItem.item.site.country',
            'unitItem.item.site.legalEntity',
        ]);

        /** @var Collection<int, ContractItem> $contractItems */
        $contractItems = $contract->items()->whereNull('effective_to')->with('price')->get();
        if ($contractItems->isEmpty()) {
            $contractItems = $contract->items->whereNull('effective_to')->values();
        }

        CurrencyGuard::assertItemsAgree($contractItems);

        $moveIn = CarbonImmutable::parse(
            (string) ($contract->move_in_date ?? $contract->start_date)
        )->startOfDay();

        $from = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);

        if ($from === ContractStatus::AwaitingSignature) {
            self::releaseSignatureHolds($contract);
        }

        self::writeUnitOccupancies($contract, $contractItems, $moveIn, $endedOn, $createdBy);

        $billing = Setting::billing();
        $plan = ContractBilling::planFirstPeriod(
            $moveIn,
            $contract->billing_anchor_model,
            $contract->billing_interval,
            (int) $contract->billing_interval_count,
            $billing->billingAnchorDay,
        );

        self::generateFirstPeriodCharges(
            $contract,
            $contractItems,
            $plan,
            $contract->proration_method,
            $moveIn,
        );

        $contract->load(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);
        $charges = Charge::query()->where('contract_id', $contract->id)->get();
        InvoiceIssuer::issue($contract, $charges, null, $createdBy);

        if ($contract->signed_at === null) {
            $contract->forceFill([
                'signed_at' => $signedAt ?? now(),
            ])->save();
        }

        if ($from === ContractStatus::AwaitingSignature) {
            $site = $contract->unitItem?->item?->site;
            $today = $site !== null
                ? SiteClock::today($site)
                : CarbonImmutable::today()->startOfDay();
            $target = $moveIn->toDateString() > $today->toDateString()
                ? ContractStatus::Pending
                : ContractStatus::Active;

            ContractTransition::apply($contract, $target);
        }

        $signedProps = ['reservation_id' => $contract->reservation_id];
        RecordsActivity::core('contract.signed', $contract, $signedProps);
        $contract->loadMissing('contact');
        if ($contract->contact !== null) {
            RecordsActivity::core('contract.signed', $contract->contact, $signedProps);
        }
    }

    /**
     * Cancel an awaiting (or otherwise cancellable) contract: release signature
     * holds, set ended_reason, claim-transition — zero ledger effect.
     *
     * @throws ValidationException
     */
    public static function cancel(Contract $contract): void
    {
        $contract->refresh();

        // Best-effort provider cancel for any live envelope before the status claim.
        app(EnvelopeOrchestrator::class)->cancelLiveForContract($contract);

        self::releaseSignatureHolds($contract);

        ContractTransition::apply($contract, ContractStatus::Cancelled);

        $contract->forceFill([
            'ended_reason' => ContractEndedReason::Cancelled,
        ])->save();
    }

    public static function releaseSignatureHolds(Contract $contract): void
    {
        UnitHold::query()
            ->where('contract_id', $contract->id)
            ->where('hold_type', HoldType::ContractSignature->value)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    /**
     * Place open-ended contract_signature holds for each unit item.
     * Asserts vacant + unheld. Must run inside the caller's transaction.
     *
     * @param  Collection<int, ContractItem>  $contractItems
     */
    public static function writeSignatureHolds(
        Contract $contract,
        Collection $contractItems,
        ?int $createdBy = null,
    ): void {
        foreach ($contractItems as $item) {
            if ($item->item_type !== 'unit') {
                continue;
            }

            $unitId = (int) $item->item_id;
            $unit = Unit::query()->with('site')->find($unitId);
            $site = $unit?->site;

            $startsOn = $site !== null
                ? SiteClock::today($site)
                : CarbonImmutable::today()->startOfDay();

            OccupancyGuard::assertVacant($unitId, $startsOn, null);
            HoldGuard::assertUnheld($unitId, $startsOn, null);

            UnitHold::query()->create([
                'unit_id'        => $unitId,
                'hold_type'      => HoldType::ContractSignature,
                'reservation_id' => null,
                'contract_id'    => $contract->id,
                'starts_on'      => $startsOn->format('Y-m-d'),
                'ends_on'        => null,
                'released_at'    => null,
                'reason'         => null,
                'created_by'     => $createdBy,
            ]);
        }
    }

    /**
     * @param  Collection<int, ContractItem>  $contractItems
     */
    private static function writeUnitOccupancies(
        Contract $contract,
        Collection $contractItems,
        CarbonImmutable $moveIn,
        ?CarbonImmutable $endedOn,
        ?int $createdBy,
    ): void {
        foreach ($contractItems as $item) {
            if ($item->item_type !== 'unit') {
                continue;
            }

            OccupancyGuard::assertVacant((int) $item->item_id, $moveIn, $endedOn);
            HoldGuard::assertUnheld((int) $item->item_id, $moveIn, $endedOn);

            UnitOccupancy::query()->create([
                'unit_id'          => $item->item_id,
                'contract_id'      => $contract->id,
                'contract_item_id' => $item->id,
                'started_on'       => $moveIn->format('Y-m-d'),
                'ended_on'         => $endedOn?->format('Y-m-d'),
                'created_by'       => $createdBy,
            ]);
        }
    }

    /**
     * @param  Collection<int, ContractItem>  $contractItems
     */
    private static function generateFirstPeriodCharges(
        Contract $contract,
        Collection $contractItems,
        FirstPeriodPlan $plan,
        ProrationMethod|string $prorationMethod,
        CarbonImmutable $moveIn,
    ): void {
        $method = $prorationMethod instanceof ProrationMethod
            ? $prorationMethod
            : ProrationMethod::from($prorationMethod);

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
                    'charge_type'        => self::chargeTypeForItem($item->item_type),
                    'period_start'       => $plan->windowStart->toDateString(),
                    'period_end'         => $plan->windowEnd->toDateString(),
                    'net_amount'         => $breakdown->net,
                    'tax_rate_snapshot'  => $item->tax_rate_snapshot,
                    'tax_amount'         => $breakdown->tax,
                    'amount'             => $breakdown->gross,
                    'currency'           => $contract->currency,
                    'due_date'           => $moveIn->toDateString(),
                    'description'        => self::chargeDescriptionForItem($item->item_type),
                ]);
            }

            self::maybeCreateDepositCharge($contract, $moveIn);
        }

        $contract->forceFill([
            'billing_anchor_date' => $plan->anchorDate->toDateString(),
            'billed_through'      => $plan->billedThrough->toDateString(),
        ])->save();
    }

    private static function maybeCreateDepositCharge(Contract $contract, CarbonImmutable $moveIn): void
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

    private static function chargeTypeForItem(string $itemType): ChargeType
    {
        return match ($itemType) {
            'unit'      => ChargeType::Rent,
            'insurance' => ChargeType::Insurance,
            default     => ChargeType::from($itemType),
        };
    }

    private static function chargeDescriptionForItem(string $itemType): string
    {
        return match ($itemType) {
            'unit'      => 'Rent',
            'insurance' => 'Insurance',
            default     => ucfirst($itemType),
        };
    }
}
