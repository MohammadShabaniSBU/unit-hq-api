<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AutopayAttemptStatus;
use App\Enums\AutopayAttemptTrigger;
use App\Enums\PaymentInstrumentType;
use App\Models\AutopayAttempt;
use App\Models\Contract;
use App\Models\PaymentMethod;
use App\Support\Billing\RecurringBilling;
use App\Support\Payments\AutopayCollector;
use App\Support\Payments\PaymentsNotConfigured;
use App\Support\Payments\ProviderAccountResolver;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Per-contract autopay enable/disable and manual retry (S06-04).
 */
class ContractAutopayController extends Controller
{
    public function update(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::PaymentRecord->value, $contract);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'payment_method_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $enabled = (bool) $validated['enabled'];

        if ($enabled) {
            $method = $this->resolveMethodForEnable(
                $contract,
                isset($validated['payment_method_id']) ? (int) $validated['payment_method_id'] : null,
            );

            $contract->forceFill([
                'autopay_enabled' => true,
                'payment_method_id' => $method->id,
            ])->save();
        } else {
            $updates = ['autopay_enabled' => false];

            if (array_key_exists('payment_method_id', $validated)) {
                $pmId = $validated['payment_method_id'];
                if ($pmId !== null) {
                    $method = $this->resolveMethodForEnable($contract, (int) $pmId);
                    $updates['payment_method_id'] = $method->id;
                } else {
                    $updates['payment_method_id'] = null;
                }
            }

            $contract->forceFill($updates)->save();
        }

        RecordsActivity::core('contract.autopay.updated', $contract, [
            'enabled' => $contract->autopay_enabled,
            'payment_method_id' => $contract->payment_method_id,
        ]);

        $contract->load(['paymentMethod', 'autopayAttempts' => fn ($q) => $q->latest('id')->limit(1)]);

        return $this->success(
            $this->autopayPayload($contract),
            'Autopay updated successfully.',
        );
    }

    public function retry(Contract $contract, AutopayCollector $collector): JsonResponse
    {
        Gate::authorize(Permission::PaymentRecord->value, $contract);

        if (! $contract->autopay_enabled || $contract->payment_method_id === null) {
            throw ValidationException::withMessages([
                'autopay' => ['Autopay is not enabled for this contract.'],
            ]);
        }

        /** @var AutopayAttempt|null $latest */
        $latest = $contract->autopayAttempts()->latest('id')->first();

        if ($latest === null || $latest->status !== AutopayAttemptStatus::Failed) {
            throw ValidationException::withMessages([
                'autopay' => ['No failed autopay attempt to retry.'],
            ]);
        }

        if ($contract->autopayAttempts()
            ->where('status', AutopayAttemptStatus::Pending)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'autopay' => ['An autopay attempt is already in progress.'],
            ]);
        }

        $attempts = $collector->collect(
            trigger: AutopayAttemptTrigger::Manual,
            contractIds: [(int) $contract->id],
        );

        RecordsActivity::core('contract.autopay.retried', $contract, [
            'previous_attempt_id' => $latest->id,
            'new_attempt_id' => $attempts[0]->id ?? null,
        ]);

        $contract->load(['paymentMethod', 'autopayAttempts' => fn ($q) => $q->latest('id')->limit(1)]);

        return $this->success(
            [
                'autopay' => $this->autopayPayload($contract),
                'attempt' => isset($attempts[0]) ? $this->attemptPayload($attempts[0]) : null,
            ],
            'Autopay retry submitted successfully.',
        );
    }

    public function show(Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::PaymentView->value, $contract);

        $contract->load(['paymentMethod', 'autopayAttempts' => fn ($q) => $q->latest('id')->limit(1)]);

        return $this->success(
            $this->autopayPayload($contract),
            'Autopay settings retrieved successfully.',
        );
    }

    private function resolveMethodForEnable(Contract $contract, ?int $paymentMethodId): PaymentMethod
    {
        try {
            $account = ProviderAccountResolver::forContract($contract);
        } catch (PaymentsNotConfigured $e) {
            throw ValidationException::withMessages([
                'payments' => [$e->getMessage()],
            ]);
        }

        if ($paymentMethodId !== null) {
            /** @var PaymentMethod|null $method */
            $method = PaymentMethod::query()->find($paymentMethodId);
        } else {
            /** @var PaymentMethod|null $method */
            $method = PaymentMethod::query()
                ->active()
                ->where('contact_id', $contract->contact_id)
                ->where('payment_provider_account_id', $account->id)
                ->where('type', PaymentInstrumentType::StripeCard)
                ->where('is_default', true)
                ->first();

            if ($method === null) {
                $method = PaymentMethod::query()
                    ->active()
                    ->where('contact_id', $contract->contact_id)
                    ->where('payment_provider_account_id', $account->id)
                    ->where('type', PaymentInstrumentType::StripeCard)
                    ->orderByDesc('id')
                    ->first();
            }
        }

        if ($method === null) {
            throw ValidationException::withMessages([
                'payment_method_id' => ['No eligible card on file for this contract\'s entity. Save a card first.'],
            ]);
        }

        if ((int) $method->contact_id !== (int) $contract->contact_id) {
            throw ValidationException::withMessages([
                'payment_method_id' => ['Payment method does not belong to this contract\'s contact.'],
            ]);
        }

        if ($method->type !== PaymentInstrumentType::StripeCard) {
            throw ValidationException::withMessages([
                'payment_method_id' => ['Only Stripe cards can be used for autopay.'],
            ]);
        }

        if ($method->isArchived()) {
            throw ValidationException::withMessages([
                'payment_method_id' => ['Archived payment methods cannot be used for autopay.'],
            ]);
        }

        if ((int) $method->payment_provider_account_id !== (int) $account->id) {
            throw ValidationException::withMessages([
                'payment_method_id' => [
                    'This card belongs to a different legal entity\'s Stripe account. '
                    .'Save a card under this contract\'s entity before enabling autopay.',
                ],
            ]);
        }

        return $method;
    }

    /**
     * @return array<string, mixed>
     */
    private function autopayPayload(Contract $contract): array
    {
        $method = $contract->relationLoaded('paymentMethod')
            ? $contract->paymentMethod
            : $contract->paymentMethod()->first();

        /** @var AutopayAttempt|null $last */
        $last = $contract->relationLoaded('autopayAttempts')
            ? $contract->autopayAttempts->first()
            : $contract->autopayAttempts()->latest('id')->first();

        $nextBill = RecurringBilling::nextBillEstimate($contract);

        return [
            'enabled' => (bool) $contract->autopay_enabled,
            'payment_method_id' => $contract->payment_method_id,
            'payment_method' => $method !== null ? [
                'id' => $method->id,
                'display_label' => $method->display_label,
                'type' => $method->type?->value,
                'is_default' => (bool) $method->is_default,
            ] : null,
            'last_attempt' => $last !== null ? $this->attemptPayload($last) : null,
            'next_collection' => $nextBill !== null ? [
                'date' => $nextBill['window']['start'] ?? null,
                'amount' => $nextBill['amount'] ?? null,
                'currency' => $nextBill['currency'] ?? null,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptPayload(AutopayAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'status' => $attempt->status instanceof \BackedEnum
                ? $attempt->status->value
                : (string) $attempt->status,
            'amount' => (string) $attempt->amount,
            'currency' => $attempt->currency,
            'failure_code' => $attempt->failure_code,
            'decline_code' => $attempt->decline_code,
            'failure_message' => $attempt->failure_message,
            'triggered_by' => $attempt->triggered_by instanceof \BackedEnum
                ? $attempt->triggered_by->value
                : (string) $attempt->triggered_by,
            'attempted_at' => $attempt->attempted_at?->toIso8601String(),
            'resolved_at' => $attempt->resolved_at?->toIso8601String(),
        ];
    }
}
