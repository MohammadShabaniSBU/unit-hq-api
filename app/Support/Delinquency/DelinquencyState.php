<?php

declare(strict_types=1);

namespace App\Support\Delinquency;

use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Site;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Pure ledger-derived delinquency severity. Never stores stage/amount/days.
 *
 * Two charge-type sets (both tested):
 * - TRIGGER: unpaid non-deposit charges keep a case open
 * - FEE_BASE: late-fee percent applies only to rent|insurance (no fee-on-fee)
 */
final class DelinquencyState
{
    /** @var list<ChargeType> Charge types that can keep a case open. */
    public const TRIGGER_TYPES = [
        ChargeType::Rent,
        ChargeType::Insurance,
        ChargeType::LateFee,
        ChargeType::LienFee,
        ChargeType::Other,
        ChargeType::Adjustment,
        ChargeType::WriteOff,
        ChargeType::Refund,
    ];

    /** @var list<ChargeType> Fee base — rent + insurance only. */
    public const FEE_BASE_TYPES = [
        ChargeType::Rent,
        ChargeType::Insurance,
    ];

    /**
     * Overdue charges that trigger / keep delinquency open:
     * due_date < site-today, open amount > 0, type in TRIGGER_TYPES.
     *
     * @return Collection<int, Charge>
     */
    public static function overdueCharges(Contract $contract): Collection
    {
        $today = self::siteToday($contract)->toDateString();
        $triggerValues = array_map(fn (ChargeType $t): string => $t->value, self::TRIGGER_TYPES);

        if ($contract->relationLoaded('charges')) {
            return $contract->charges
                ->filter(function (Charge $charge) use ($today, $triggerValues): bool {
                    $due = $charge->due_date?->toDateString() ?? (string) $charge->due_date;
                    $type = $charge->charge_type instanceof ChargeType
                        ? $charge->charge_type->value
                        : (string) $charge->charge_type;

                    return $due < $today
                        && in_array($type, $triggerValues, true)
                        && bccomp($charge->openAmount(), '0.00', 2) > 0;
                })
                ->sortBy(fn (Charge $c): string => sprintf(
                    '%s-%010d',
                    $c->due_date?->toDateString() ?? (string) $c->due_date,
                    $c->id,
                ))
                ->values();
        }

        return $contract->charges()
            ->with('allocations')
            ->where('due_date', '<', $today)
            ->whereIn('charge_type', $triggerValues)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Charge $charge): bool => bccomp($charge->openAmount(), '0.00', 2) > 0)
            ->values();
    }

    public static function isDelinquent(Contract $contract): bool
    {
        // Net open overdue across trigger types so a negative write_off can
        // zero the balance and stop the case reopening on the next run.
        return bccomp(self::netOverdueAmount($contract), '0.00', 2) > 0;
    }

    /**
     * Σ open amounts of overdue trigger-type charges (can be reduced by write_offs).
     */
    public static function netOverdueAmount(Contract $contract): string
    {
        $today = self::siteToday($contract)->toDateString();
        $triggerValues = array_map(fn (ChargeType $t): string => $t->value, self::TRIGGER_TYPES);

        if ($contract->relationLoaded('charges')) {
            $charges = $contract->charges->filter(function (Charge $charge) use ($today, $triggerValues): bool {
                $due = $charge->due_date?->toDateString() ?? (string) $charge->due_date;
                $type = $charge->charge_type instanceof ChargeType
                    ? $charge->charge_type->value
                    : (string) $charge->charge_type;

                return $due < $today && in_array($type, $triggerValues, true);
            });
        } else {
            $charges = $contract->charges()
                ->with('allocations')
                ->where('due_date', '<', $today)
                ->whereIn('charge_type', $triggerValues)
                ->get();
        }

        $sum = '0.00';
        foreach ($charges as $charge) {
            $sum = bcadd($sum, $charge->openAmount(), 2);
        }

        return BillingMath::round2($sum);
    }

    /**
     * Site-today − oldest unpaid trigger charge due date, or null if not delinquent.
     */
    public static function daysOverdue(Contract $contract): ?int
    {
        $charges = self::overdueCharges($contract);
        if ($charges->isEmpty()) {
            return null;
        }

        /** @var Charge $oldest */
        $oldest = $charges->first();
        $today = self::siteToday($contract);
        $due = CarbonImmutable::parse($oldest->due_date->toDateString());

        return BillingMath::daysBetween($due, $today);
    }

    /**
     * Σ open gross of rent|insurance overdue charges — the no-fee-on-fee base.
     */
    public static function overdueBase(Contract $contract): string
    {
        $today = self::siteToday($contract)->toDateString();
        $baseValues = array_map(fn (ChargeType $t): string => $t->value, self::FEE_BASE_TYPES);

        $charges = $contract->charges()
            ->with('allocations')
            ->where('due_date', '<', $today)
            ->whereIn('charge_type', $baseValues)
            ->get();

        $sum = '0.00';
        foreach ($charges as $charge) {
            $open = $charge->openAmount();
            if (bccomp($open, '0.00', 2) > 0) {
                $sum = bcadd($sum, $open, 2);
            }
        }

        return BillingMath::round2($sum);
    }

    public static function siteToday(Contract $contract): CarbonImmutable
    {
        return SiteClock::today(self::resolveSite($contract));
    }

    public static function resolveSite(Contract $contract): Site
    {
        $contract->loadMissing(['unitItem.item']);

        $unit = $contract->unitItem?->item;
        if (! $unit instanceof Unit) {
            throw new RuntimeException("Contract {$contract->id} has no unit item; cannot resolve site timezone.");
        }

        $unit->loadMissing('site');

        return $unit->site;
    }
}
