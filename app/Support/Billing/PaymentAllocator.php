<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Allocation;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolve and write payment→charge allocations.
 * Explicit list is validated against payment total and per-charge open amount;
 * omitted list auto-allocates oldest-due-first across open positive charges.
 */
final class PaymentAllocator
{
    /**
     * @param  list<array{charge_id: int, amount: string}>|null  $explicit
     * @return Collection<int, Allocation>
     *
     * @throws ValidationException
     */
    public static function allocate(Contract $contract, Payment $payment, ?array $explicit = null): Collection
    {
        $paymentAmount = BillingMath::round2((string) $payment->amount);

        if (bccomp($paymentAmount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => [__('errors.payments.amount_must_be_positive')],
            ]);
        }

        $plan = $explicit === null
            ? self::planOldestDueFirst($contract, $paymentAmount)
            : self::planExplicit($contract, $paymentAmount, $explicit);

        $created = collect();

        foreach ($plan as $row) {
            $created->push(Allocation::query()->create([
                'payment_id' => $payment->id,
                'charge_id' => $row['charge_id'],
                'amount' => $row['amount'],
            ]));
        }

        return $created;
    }

    /**
     * @return list<array{charge_id: int, amount: string}>
     */
    public static function planOldestDueFirst(Contract $contract, string $paymentAmount): array
    {
        $remaining = BillingMath::round2($paymentAmount);
        $plan = [];

        $charges = Charge::query()
            ->where('contract_id', $contract->id)
            ->with('allocations')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        foreach ($charges as $charge) {
            if (bccomp($remaining, '0', 2) <= 0) {
                break;
            }

            $open = $charge->openAmount();

            if (bccomp($open, '0', 2) <= 0) {
                continue;
            }

            $take = bccomp($open, $remaining, 2) <= 0 ? $open : $remaining;
            $plan[] = [
                'charge_id' => (int) $charge->id,
                'amount' => BillingMath::round2($take),
            ];
            $remaining = bcsub($remaining, $take, 2);
        }

        return $plan;
    }

    /**
     * @param  list<array{charge_id: int, amount: string|float|int}>  $explicit
     * @return list<array{charge_id: int, amount: string}>
     *
     * @throws ValidationException
     */
    public static function planExplicit(Contract $contract, string $paymentAmount, array $explicit): array
    {
        $paymentAmount = BillingMath::round2($paymentAmount);
        $plan = [];
        $total = '0.00';
        /** @var array<int, string> $byCharge */
        $byCharge = [];

        foreach ($explicit as $index => $row) {
            $chargeId = (int) ($row['charge_id'] ?? 0);
            $amount = BillingMath::round2((string) ($row['amount'] ?? '0'));

            if ($chargeId <= 0 || bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => [__('errors.payments.allocation_amount_invalid')],
                ]);
            }

            $byCharge[$chargeId] = isset($byCharge[$chargeId])
                ? bcadd($byCharge[$chargeId], $amount, 2)
                : $amount;
            $total = bcadd($total, $amount, 2);
        }

        if (bccomp($total, $paymentAmount, 2) > 0) {
            throw ValidationException::withMessages([
                'allocations' => [__('errors.payments.over_allocation_payment')],
            ]);
        }

        $charges = Charge::query()
            ->where('contract_id', $contract->id)
            ->whereIn('id', array_keys($byCharge))
            ->with('allocations')
            ->get()
            ->keyBy('id');

        foreach ($byCharge as $chargeId => $amount) {
            /** @var Charge|null $charge */
            $charge = $charges->get($chargeId);

            if ($charge === null) {
                throw ValidationException::withMessages([
                    'allocations' => [__('errors.payments.charge_not_on_contract')],
                ]);
            }

            $open = $charge->openAmount();

            if (bccomp($amount, $open, 2) > 0) {
                throw ValidationException::withMessages([
                    'allocations' => [__('errors.payments.over_allocation_charge')],
                ]);
            }

            $plan[] = [
                'charge_id' => $chargeId,
                'amount' => $amount,
            ];
        }

        return $plan;
    }
}
