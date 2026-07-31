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
 * transfer, cancel) must assert through here inside the caller's transaction.
 */
final class ContractTransition
{
    /**
     * @var array<string, list<ContractStatus>>
     */
    private const MAP = [
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
                $from === ContractStatus::Active
                && $to === ContractStatus::Cancelled
                && self::hasPayments($contract)
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
            if (
                $from === ContractStatus::Active
                && $target === ContractStatus::Cancelled
                && self::hasPayments($contract)
            ) {
                continue;
            }

            $values[] = $target->value;
        }

        return $values;
    }

    /**
     * Assert, apply status side effects, persist status, and log
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

        $contract->status = $to;
        $contract->save();

        RecordsActivity::core('contract.status_changed', $contract, [
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    private static function isPermitted(Contract $contract, ContractStatus $to): bool
    {
        return in_array($to->value, self::allowed($contract), true);
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
