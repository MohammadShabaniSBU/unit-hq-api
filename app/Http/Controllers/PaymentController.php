<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Resources\PaymentResource;
use App\Models\Allocation;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Payment;
use App\Support\Billing\BillingMath;
use App\Support\Billing\PaymentAllocator;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function store(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::PaymentRecord->value, $contract);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in([
                PaymentMethod::Cash->value,
                PaymentMethod::BankTransfer->value,
                PaymentMethod::CardExternal->value,
            ])],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.charge_id' => ['required_with:allocations', 'integer'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'gt:0'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();

        $amount = BillingMath::round2((string) $validated['amount']);
        $explicit = isset($validated['allocations'])
            ? array_map(
                static fn (array $row): array => [
                    'charge_id' => (int) $row['charge_id'],
                    'amount' => BillingMath::round2((string) $row['amount']),
                ],
                $validated['allocations'],
            )
            : null;

        $payment = DB::transaction(function () use ($contract, $validated, $amount, $explicit, $employee): Payment {
            $payment = Payment::query()->create([
                'contract_id' => $contract->id,
                'amount' => $amount,
                'currency' => $contract->currency,
                'method' => $validated['method'],
                'received_on' => $validated['received_on'],
                'reference' => $validated['reference'] ?? null,
                'stripe_payment_intent_id' => null,
                'idempotency_key' => 'manual:'.Str::uuid(),
                'reversal_of_payment_id' => null,
            ]);

            $allocations = PaymentAllocator::allocate($contract, $payment, $explicit);

            RecordsActivity::core('payment.recorded', $payment, [
                'amount' => $amount,
                'currency' => (string) $contract->currency,
                'method' => $payment->method?->value ?? (string) $validated['method'],
                'reference' => $payment->reference,
                'received_on' => $payment->received_on?->toDateString() ?? (string) $validated['received_on'],
                'contract_id' => $contract->id,
                'allocation_count' => $allocations->count(),
                'allocated_total' => BillingMath::round2((string) $allocations->sum('amount')),
            ], $employee);

            return $payment->load('allocations');
        });

        return $this->created(
            PaymentResource::make($payment),
            'Payment recorded successfully.'
        );
    }

    public function reverse(Request $request, Payment $payment): JsonResponse
    {
        Gate::authorize(Permission::PaymentRefund->value, $payment);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($payment->reversal_of_payment_id !== null) {
            throw ValidationException::withMessages([
                'payment' => [__('errors.payments.cannot_reverse_reversal')],
            ]);
        }

        $alreadyReversed = Payment::query()
            ->where('reversal_of_payment_id', $payment->id)
            ->exists();

        if ($alreadyReversed) {
            throw ValidationException::withMessages([
                'payment' => [__('errors.payments.already_reversed')],
            ]);
        }

        /** @var Employee $employee */
        $employee = $request->user();

        $reversal = DB::transaction(function () use ($payment, $validated, $employee): Payment {
            $payment->loadMissing('allocations');

            $negativeAmount = bcmul((string) $payment->amount, '-1', 2);

            $reversal = Payment::query()->create([
                'contract_id' => $payment->contract_id,
                'amount' => $negativeAmount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'received_on' => $payment->received_on,
                'reference' => $payment->reference,
                'stripe_payment_intent_id' => null,
                'idempotency_key' => 'manual:'.Str::uuid(),
                'reversal_of_payment_id' => $payment->id,
            ]);

            foreach ($payment->allocations as $allocation) {
                Allocation::query()->create([
                    'payment_id' => $reversal->id,
                    'charge_id' => $allocation->charge_id,
                    'amount' => bcmul((string) $allocation->amount, '-1', 2),
                ]);
            }

            RecordsActivity::core('payment.reversed', $reversal, [
                'amount' => $negativeAmount,
                'currency' => (string) $payment->currency,
                'method' => $payment->method?->value,
                'reference' => $payment->reference,
                'reason' => $validated['reason'],
                'reversal_of_payment_id' => $payment->id,
                'contract_id' => $payment->contract_id,
            ], $employee);

            return $reversal->load('allocations');
        });

        return $this->created(
            PaymentResource::make($reversal),
            'Payment reversed successfully.'
        );
    }
}
