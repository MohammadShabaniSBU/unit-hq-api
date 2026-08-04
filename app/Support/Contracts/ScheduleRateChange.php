<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Enums\ContractItemChangeReason;
use App\Enums\ContractNoticeType;
use App\Enums\ContractStatus;
use App\Enums\DiscountKind;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Discount;
use App\Models\Employee;
use App\Support\Billing\BillingMath;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Discounts\RecomputesDiscountedAmount;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Minimal S02 rate-change store: supersede item version + write contract_notice.
 * When the open version carries a discount, new_amount is the new list and the
 * contract amount is recomputed (DISC-02).
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

            $item->loadMissing(['price', 'discount']);
            $listAmount = BillingMath::round2($newAmount);
            $currentAmount = BillingMath::round2((string) ($item->price?->amount ?? '0'));

            /** @var Discount|null $discount */
            $discount = $item->discount_id !== null ? $item->discount : null;

            $recomputed = RecomputesDiscountedAmount::recompute(
                $discount,
                $listAmount,
                $currentAmount,
                $item->base_rate !== null ? (string) $item->base_rate : null,
            );
            $amount = $recomputed['amount'];
            $baseRateOverride = $discount !== null ? $recomputed['list_amount'] : null;

            $written = WritesContractItemVersion::supersede(
                $item,
                $amount,
                $effective->toDateString(),
                $site->id,
                $createdBy?->id,
                ContractItemChangeReason::RateChange,
                WritesContractItemVersion::PROVENANCE_CARRY,
                $baseRateOverride,
            );

            $successor = $written['item'];
            $previous = $written['previous'];

            $notice = ContractNotice::query()->create([
                'contract_id' => $contract->id,
                'notice_type' => ContractNoticeType::RateChange,
                'effective_date' => $effective->toDateString(),
                'required_by' => $requiredBy->toDateString(),
                'short_notice_reason' => $effective->lt($requiredBy) ? $shortNoticeReason : null,
                'contract_item_id' => $successor->id,
                'created_by' => $createdBy?->id,
            ]);

            $properties = [
                'contract_item_id' => $successor->id,
                'previous_item_id' => $previous->id,
                'effective_date' => $effective->toDateString(),
                'new_amount' => $amount,
                'notice_id' => $notice->id,
                'short_notice' => $effective->lt($requiredBy),
            ];

            if ($discount !== null) {
                $properties['list_amount'] = $recomputed['list_amount'];
                $properties['contract_amount'] = $amount;
                if ($recomputed['percent'] !== null
                    && $discount->kind === DiscountKind::Percent
                    && $discount->tracks_rate_changes
                ) {
                    $properties['percent'] = $recomputed['percent'];
                }
            }

            RecordsActivity::core('contract.rate_scheduled', $contract, $properties, causer: $createdBy);

            return [
                'item' => $successor,
                'notice' => $notice,
                'previous' => $previous,
            ];
        });
    }
}
