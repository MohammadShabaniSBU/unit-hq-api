<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\UnitOccupancy;
use App\Support\RecordsActivity;
use Illuminate\Validation\ValidationException;

/**
 * Contract lifecycle transition guard. Static, no state — same tier as
 * OccupancyGuard / HoldGuard. Every lifecycle action (notice, vacate,
 * transfer, cancel, signature complete) must assert through here inside
 * the caller's transaction.
 *
 * Status persistence is claim-based (S08 idiom): conditional UPDATE on
 * the prior status so a racing cancel/complete loses or wins cleanly.
 */
final class ContractTransition
{
    /**
     * @var array<string, list<ContractStatus>>
     */
    private const MAP = [
        'awaiting_signature' => [ContractStatus::Pending, ContractStatus::Active, ContractStatus::Cancelled],
        'pending' => [ContractStatus::Active, ContractStatus::Cancelled],
        'active' => [ContractStatus::NoticeGiven, ContractStatus::Ended, ContractStatus::Cancelled],
        'notice_given' => [ContractStatus::Active, ContractStatus::Ended],
        'ended' => [],
        'cancelled' => [],
    ];

    /**
     * @throws ValidationException
     */
    public static function assert(Contract $contract, ContractStatus $to): void
    {
        $from = self::statusOf($contract);

        if (! self::isPermitted($contract, $to)) {
            if (
                self::isCancelBlockedByPayments($from, $to, $contract)
            ) {
                throw ValidationException::withMessages([
                    'status' => [__('errors.contracts.cancel_with_payments')],
                ]);
            }

            throw ValidationException::withMessages([
                'status' => [__('errors.contracts.transition_not_allowed', [
                    'from' => $from->value,
                    'to' => $to->value,
                ])],
            ]);
        }
    }

    /**
     * Transfer is not a status change — only in-force contracts may transfer.
     */
    public static function canTransfer(Contract $contract): bool
    {
        $from = self::statusOf($contract);

        return $from === ContractStatus::Active || $from === ContractStatus::NoticeGiven;
    }

    /**
     * @throws ValidationException
     */
    public static function assertTransferable(Contract $contract): void
    {
        if (! self::canTransfer($contract)) {
            $from = self::statusOf($contract);

            throw ValidationException::withMessages([
                'status' => [__('errors.contracts.transfer_not_allowed', [
                    'status' => $from->value,
                ])],
            ]);
        }
    }

    /**
     * Currently-valid target statuses for the panel action menu.
     *
     * @return list<string>
     */
    public static function allowed(Contract $contract): array
    {
        $from = self::statusOf($contract);
        $targets = self::MAP[$from->value] ?? [];

        $values = [];
        foreach ($targets as $target) {
            if (self::isCancelBlockedByPayments($from, $target, $contract)) {
                continue;
            }

            $values[] = $target->value;
        }

        return $values;
    }

    /**
     * Assert, apply status side effects, claim-persist status, and log
     * contract.status_changed. Must run inside the caller's transaction.
     *
     * @throws ValidationException
     */
    public static function apply(Contract $contract, ContractStatus $to): void
    {
        self::assert($contract, $to);

        $from = self::statusOf($contract);

        if ($from === ContractStatus::NoticeGiven && $to === ContractStatus::Active) {
            $contract->notice_given_on = null;
            $contract->scheduled_move_out_on = null;

            UnitOccupancy::query()
                ->where('contract_id', $contract->id)
                ->whereNotNull('ended_on')
                ->orderByDesc('started_on')
                ->orderByDesc('id')
                ->limit(1)
                ->update(['ended_on' => null]);
        }

        $attrs = [
            'status' => $to->value,
            'updated_at' => now(),
        ];

        // Side-effect fields that live on the contract row must be claimed
        // atomically with the status flip (notice withdrawal clears).
        if ($from === ContractStatus::NoticeGiven && $to === ContractStatus::Active) {
            $attrs['notice_given_on'] = null;
            $attrs['scheduled_move_out_on'] = null;
        }

        $affected = Contract::query()
            ->whereKey($contract->id)
            ->where('status', $from->value)
            ->update($attrs);

        if ($affected === 0) {
            $contract->refresh();

            throw ValidationException::withMessages([
                'status' => [__('errors.contracts.transition_conflict')],
            ]);
        }

        $contract->refresh();

        RecordsActivity::core('contract.status_changed', $contract, [
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    private static function isPermitted(Contract $contract, ContractStatus $to): bool
    {
        return in_array($to->value, self::allowed($contract), true);
    }

    private static function isCancelBlockedByPayments(
        ContractStatus $from,
        ContractStatus $to,
        Contract $contract,
    ): bool {
        if ($to !== ContractStatus::Cancelled) {
            return false;
        }

        // Active: historical guard. Awaiting: asserted too (trivially false in v1 —
        // pre-signature deposits are a known future ask).
        if (! in_array($from, [
            ContractStatus::Active,
            ContractStatus::AwaitingSignature,
        ], true)) {
            return false;
        }

        return self::hasPayments($contract);
    }

    private static function statusOf(Contract $contract): ContractStatus
    {
        $status = $contract->status;

        return $status instanceof ContractStatus
            ? $status
            : ContractStatus::from((string) $status);
    }

    private static function hasPayments(Contract $contract): bool
    {
        return $contract->payments()->exists();
    }
}
