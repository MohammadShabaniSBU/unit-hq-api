<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Enums\ContractItemChangeReason;
use App\Enums\ContractNoticeType;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Employee;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ResolvesContractItemPrice;
use App\Support\Delinquency\DelinquencyState;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Minimal S02 rate-change store: supersede item version + write contract_notice.
 * Amend/cancel/mark-sent remain out of scope for S07-01.
 *
 * @return array{item: ContractItem, notice: ContractNotice, previous: ContractItem}
 */
final class ScheduleRateChange
{
    /**
     * @return array{item: ContractItem, notice: ContractNotice, previous: ContractItem}
     */
    public static function run(
        Contract $contract,
        int $contractItemId,
        string $newAmount,
        string $effectiveDate,
        ?Employee $createdBy = null,
        bool $acknowledgeShortNotice = false,
        ?string $shortNoticeReason = null,
    ): array {
        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);

        if (in_array($status, [ContractStatus::Ended, ContractStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'contract' => ['Rate changes are not allowed on ended or cancelled contracts.'],
            ]);
        }

        return DB::transaction(function () use (
            $contract,
            $contractItemId,
            $newAmount,
            $effectiveDate,
            $createdBy,
            $acknowledgeShortNotice,
            $shortNoticeReason,
        ) {
            $item = ContractItem::query()
                ->where('contract_id', $contract->id)
                ->whereKey($contractItemId)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                throw ValidationException::withMessages([
                    'contract_item_id' => ['No open contract item version found for this id.'],
                ]);
            }

            $site = DelinquencyState::resolveSite($contract);
            $today = SiteClock::today($site);
            $effective = CarbonImmutable::parse($effectiveDate)->startOfDay();

            if ($effective->lt($today)) {
                throw ValidationException::withMessages([
                    'effective_date' => ['Effective date cannot be before today at the site.'],
                ]);
            }

            $noticeDays = $contract->rate_change_notice_days;
            $requiredBy = $noticeDays !== null
                ? $today->addDays((int) $noticeDays)
                : $today;

            if ($effective->lt($requiredBy) && ! $acknowledgeShortNotice) {
                throw ValidationException::withMessages([
                    'effective_date' => ['Effective date is inside the notice period.'],
                    'required_by' => [$requiredBy->toDateString()],
                ]);
            }

            if ($effective->lt($requiredBy) && blank($shortNoticeReason)) {
                throw ValidationException::withMessages([
                    'short_notice_reason' => ['A reason is required when acknowledging short notice.'],
                ]);
            }

            $amount = BillingMath::round2($newAmount);
            $item->loadMissing(['price', 'item']);
            $subject = $item->item;
            $itemSiteId = $subject instanceof Unit
                ? (int) $subject->site_id
                : $site->id;

            $price = ResolvesContractItemPrice::forSigning(
                (string) $item->item_type,
                (int) $item->item_id,
                $amount,
                $itemSiteId,
                $createdBy?->id,
                $item->price,
            );

            $item->forceFill([
                'effective_to' => $effective->toDateString(),
            ])->save();

            $successor = ContractItem::query()->create([
                'contract_id' => $contract->id,
                'item_type' => $item->item_type,
                'item_id' => $item->item_id,
                'price_id' => $price->id,
                'discount_id' => $item->discount_id,
                'base_rate' => $item->base_rate,
                'discount_ends_at' => $item->discount_ends_at,
                'tax_rate_id' => $item->tax_rate_id,
                'tax_rate_snapshot' => $item->tax_rate_snapshot,
                'declared_goods_value' => $item->declared_goods_value,
                'description' => $item->description,
                'effective_from' => $effective->toDateString(),
                'effective_to' => null,
                'supersedes_id' => $item->id,
                'change_reason' => ContractItemChangeReason::RateChange,
            ]);

            $notice = ContractNotice::query()->create([
                'contract_id' => $contract->id,
                'notice_type' => ContractNoticeType::RateChange,
                'effective_date' => $effective->toDateString(),
                'required_by' => $requiredBy->toDateString(),
                'short_notice_reason' => $effective->lt($requiredBy) ? $shortNoticeReason : null,
                'contract_item_id' => $successor->id,
                'created_by' => $createdBy?->id,
            ]);

            RecordsActivity::core('contract.rate_scheduled', $contract, [
                'contract_item_id' => $successor->id,
                'previous_item_id' => $item->id,
                'effective_date' => $effective->toDateString(),
                'new_amount' => $amount,
                'notice_id' => $notice->id,
                'short_notice' => $effective->lt($requiredBy),
            ], causer: $createdBy);

            return [
                'item' => $successor->load('price'),
                'notice' => $notice,
                'previous' => $item->fresh() ?? $item,
            ];
        });
    }
}
