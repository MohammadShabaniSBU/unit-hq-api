<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Enums\ContractItemChangeReason;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Employee;
use App\Support\Billing\BillingMath;
use App\Support\Contracts\WritesContractItemVersion;
use App\Support\Delinquency\DelinquencyState;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operator remove-discount: list price from the next period boundary, future
 * free segments collapsed, provenance closed, Tier-3 audit (DISC-02).
 */
final class RemovesDiscount
{
    /**
     * @return array{item: ContractItem, previous: ContractItem, boundary: string}
     */
    public static function run(
        Contract $contract,
        string $reason,
        ?Employee $removedBy = null,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A reason is required to remove a discount.'],
            ]);
        }

        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);

        if (in_array($status, [ContractStatus::Ended, ContractStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'contract' => ['Discounts cannot be removed on ended or cancelled contracts.'],
            ]);
        }

        return DB::transaction(function () use ($contract, $reason, $removedBy) {
            $site = DelinquencyState::resolveSite($contract);
            $today = SiteClock::today($site);
            $boundary = self::nextPeriodBoundary($contract, $today);

            $versions = ContractItem::query()
                ->where('contract_id', $contract->id)
                ->where('item_type', 'unit')
                ->whereNotNull('discount_id')
                ->orderBy('effective_from')
                ->lockForUpdate()
                ->get();

            if ($versions->isEmpty()) {
                throw ValidationException::withMessages([
                    'discount' => ['This contract has no active discount to remove.'],
                ]);
            }

            $active = $versions->first(fn (ContractItem $v): bool => $v->discount_removed_at === null);
            if ($active === null) {
                throw ValidationException::withMessages([
                    'discount' => ['This contract has no active discount to remove.'],
                ]);
            }

            WritesContractItemVersion::cancelFrom($versions, $boundary);

            $cover = ContractItem::query()
                ->where('contract_id', $contract->id)
                ->where('item_type', 'unit')
                ->where('effective_from', '<', $boundary)
                ->where(function ($q) use ($boundary): void {
                    $q->whereNull('effective_to')
                        ->orWhere('effective_to', '>', $boundary);
                })
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();

            $listAmount = BillingMath::round2((string) (
                $active->base_rate
                ?? $versions->sortByDesc('effective_from')->first()?->base_rate
                ?? '0'
            ));

            $source = $cover;
            if ($source === null) {
                $source = ContractItem::query()
                    ->where('contract_id', $contract->id)
                    ->where('item_type', 'unit')
                    ->where('effective_from', '<', $boundary)
                    ->where('effective_to', $boundary)
                    ->orderByDesc('effective_from')
                    ->lockForUpdate()
                    ->first();
            }

            if ($source === null) {
                throw ValidationException::withMessages([
                    'discount' => ['Unable to locate a version to restore list price from.'],
                ]);
            }

            $written = WritesContractItemVersion::supersede(
                $source,
                $listAmount,
                $boundary,
                $site->id,
                $removedBy?->id,
                ContractItemChangeReason::DiscountRemoved,
                WritesContractItemVersion::PROVENANCE_CLEAR,
            );
            $previous = $written['previous'];
            $successor = $written['item'];

            $removedAt = now();
            ContractItem::query()
                ->where('contract_id', $contract->id)
                ->where('item_type', 'unit')
                ->whereNotNull('discount_id')
                ->whereNull('discount_removed_at')
                ->update([
                    'discount_removed_at' => $removedAt,
                    'discount_removed_by' => $removedBy?->id,
                    'discount_removed_reason' => $reason,
                ]);

            $previous->refresh()->loadMissing('price');
            $previousAmount = BillingMath::round2((string) ($previous->price?->amount ?? '0'));

            RecordsActivity::core('contract.discount_removed', $contract, [
                'contract_item_id' => $successor->id,
                'previous_item_id' => $previous->id,
                'effective_date' => $boundary,
                'list_amount' => $listAmount,
                'previous_amount' => $previousAmount,
                'reason' => $reason,
                'discount_id' => $active->discount_id,
            ], causer: $removedBy);

            return [
                'item' => $successor->load('price'),
                'previous' => $previous->fresh(['price']) ?? $previous,
                'boundary' => $boundary,
            ];
        });
    }

    public static function nextPeriodBoundary(Contract $contract, CarbonImmutable $today): string
    {
        $cursorStr = $contract->billedThrough()
            ?? ($contract->move_in_date?->toDateString() ?? $contract->start_date?->toDateString());

        if ($cursorStr === null) {
            throw ValidationException::withMessages([
                'contract' => ['Contract has no billing cursor to compute a period boundary.'],
            ]);
        }

        $cursor = CarbonImmutable::parse($cursorStr)->startOfDay();
        $today = $today->startOfDay();

        // Walk periods until we find the boundary after the current (possibly mid) period.
        for ($i = 0; $i < 48; $i++) {
            $window = BillingMath::nextPeriod($contract, $cursor);

            if ($today->lt($window['start'])) {
                return $window['start']->toDateString();
            }

            if ($today->lt($window['end'])) {
                // Mid-period (or on start): restore list from exclusive end = next boundary.
                return $window['end']->toDateString();
            }

            // today >= end → advance
            $cursor = $window['end'];
        }

        throw ValidationException::withMessages([
            'contract' => ['Unable to compute next period boundary for discount removal.'],
        ]);
    }
}
