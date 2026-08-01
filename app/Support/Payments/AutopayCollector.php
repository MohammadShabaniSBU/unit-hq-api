<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Enums\AutopayAttemptStatus;
use App\Enums\AutopayAttemptTrigger;
use App\Enums\PaymentInstrumentType;
use App\Models\AutopayAttempt;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\PaymentMethod;
use App\Models\SystemEvent;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Off-session collection for contracts with autopay enabled.
 * Inserts pending attempts, confirms PIs, records sync failures only —
 * success lands exclusively via ProcessStripeWebhookEvent (invariant 11 rail A).
 */
final class AutopayCollector
{
    public function __construct(
        private readonly ?StripeClient $stripe = null,
    ) {}

    /**
     * @param  list<int>|null  $contractIds  Restrict to these contracts (null = all eligible)
     * @return list<AutopayAttempt> Attempts created this invocation (pending or sync-failed)
     */
    public function collect(
        AutopayAttemptTrigger $trigger,
        ?array $contractIds = null,
        ?int $billingRunId = null,
    ): array {
        $ids = $this->eligibleContractIds($contractIds);
        $created = [];

        foreach ($ids as $id) {
            try {
                $attempt = $this->collectContract($id, $trigger, $billingRunId);
                if ($attempt !== null) {
                    $created[] = $attempt;
                }
            } catch (Throwable $e) {
                SystemEvent::record('autopay.collect.failed', Contract::query()->find($id), [
                    'contract_id' => $id,
                    'trigger' => $trigger->value,
                    'billing_run_id' => $billingRunId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    /**
     * @param  list<int>|null  $contractIds
     * @return list<int>
     */
    private function eligibleContractIds(?array $contractIds): array
    {
        $query = Contract::query()
            ->where('autopay_enabled', true)
            ->whereNotNull('payment_method_id')
            ->orderBy('id');

        if ($contractIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $contractIds)));
            if ($ids === []) {
                return [];
            }
            $query->whereIn('id', $ids);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function collectContract(
        int $contractId,
        AutopayAttemptTrigger $trigger,
        ?int $billingRunId,
    ): ?AutopayAttempt {
        /** @var Contract|null $contract */
        $contract = Contract::query()
            ->with(['paymentMethod', 'contact'])
            ->find($contractId);

        if ($contract === null || ! $contract->autopay_enabled || $contract->payment_method_id === null) {
            return null;
        }

        if ($this->hasPendingAttempt($contractId)) {
            return null;
        }

        $charges = $this->openDueCharges($contract);
        if ($charges->isEmpty()) {
            return null;
        }

        $amount = '0.00';
        $currency = null;
        $chargeIds = [];

        foreach ($charges as $charge) {
            $open = $charge->openAmount();
            if ($currency === null) {
                $currency = (string) $charge->currency;
            } elseif ($currency !== (string) $charge->currency) {
                SystemEvent::record('autopay.collect.failed', $contract, [
                    'contract_id' => $contract->id,
                    'trigger' => $trigger->value,
                    'error' => 'currency_mismatch',
                ]);

                return null;
            }

            $amount = bcadd($amount, $open, 2);
            $chargeIds[] = (int) $charge->id;
        }

        if ($chargeIds === [] || bccomp($amount, '0', 2) <= 0 || $currency === null) {
            return null;
        }

        /** @var PaymentMethod|null $method */
        $method = $contract->paymentMethod;
        if ($method === null) {
            return null;
        }

        try {
            $attempt = AutopayAttempt::query()->create([
                'contract_id' => $contract->id,
                'payment_method_id' => $method->id,
                'charge_ids' => $chargeIds,
                'amount' => $amount,
                'currency' => $currency,
                'stripe_payment_intent_id' => null,
                'status' => AutopayAttemptStatus::Pending,
                'triggered_by' => $trigger,
                'billing_run_id' => $billingRunId,
                'attempted_at' => now(),
                'resolved_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Single-flight: another collector won the pending row.
            return null;
        }

        SystemEvent::record('autopay.collect.started', $contract, [
            'autopay_attempt_id' => $attempt->id,
            'trigger' => $trigger->value,
            'amount' => $amount,
            'currency' => $currency,
            'charge_ids' => $chargeIds,
        ]);

        return $this->confirmOffSession($contract, $method, $attempt);
    }

    private function confirmOffSession(
        Contract $contract,
        PaymentMethod $method,
        AutopayAttempt $attempt,
    ): AutopayAttempt {
        if ($method->type !== PaymentInstrumentType::StripeCard
            || $method->isArchived()
            || (int) $method->contact_id !== (int) $contract->contact_id
            || $method->stripe_pm_id === null
            || $method->stripe_pm_id === ''
        ) {
            return $this->failAttempt(
                $attempt,
                'invalid_payment_method',
                null,
                'Payment method is not an active card belonging to this contract\'s contact.',
            );
        }

        try {
            $account = ProviderAccountResolver::forContract($contract);
        } catch (PaymentsNotConfigured $e) {
            return $this->failAttempt($attempt, 'payments_not_configured', null, $e->getMessage());
        }

        if ((int) $method->payment_provider_account_id !== (int) $account->id) {
            return $this->failAttempt(
                $attempt,
                'cross_entity_card',
                null,
                'This card belongs to a different legal entity\'s Stripe account. '
                .'Save a card under this contract\'s entity before collecting.',
            );
        }

        $secretKey = (string) $account->secret_key;
        if ($secretKey === '') {
            return $this->failAttempt($attempt, 'payments_not_configured', null, 'Payment provider account has no secret key.');
        }

        $stripe = $this->stripe ?? app(StripeClient::class);

        try {
            $customer = StripeCustomers::for($contract->contact, $account);
            $intent = $stripe->createPaymentIntent($secretKey, [
                'amount' => MinorUnits::toMinor((string) $attempt->amount, (string) $attempt->currency),
                'currency' => (string) $attempt->currency,
                'customer' => $customer->stripe_customer_id,
                'payment_method' => $method->stripe_pm_id,
                'confirm' => true,
                'off_session' => true,
                'metadata' => [
                    'autopay_attempt_id' => (string) $attempt->id,
                    'contract_id' => (string) $contract->id,
                ],
            ]);
        } catch (ApiErrorException $e) {
            $decline = method_exists($e, 'getDeclineCode')
                ? $e->getDeclineCode()
                : $this->declineCodeFromException($e);

            return $this->failAttempt(
                $attempt,
                $e->getStripeCode() ?: 'card_error',
                is_string($decline) && $decline !== '' ? $decline : $this->declineCodeFromException($e),
                $e->getMessage(),
                $this->paymentIntentIdFromException($e),
            );
        } catch (Throwable $e) {
            return $this->failAttempt($attempt, 'collect_error', null, $e->getMessage());
        }

        $attempt->stripe_payment_intent_id = $intent['id'];
        $attempt->save();

        $status = $intent['status'];
        if (in_array($status, ['requires_action', 'requires_source_action'], true)) {
            return $this->failAttempt(
                $attempt,
                'authentication_required',
                null,
                'Off-session payment requires authentication.',
                $intent['id'],
            );
        }

        if (in_array($status, ['requires_payment_method', 'canceled'], true)) {
            $err = $intent['last_payment_error'] ?? null;

            return $this->failAttempt(
                $attempt,
                $err['code'] ?? 'card_declined',
                $err['decline_code'] ?? null,
                $err['message'] ?? 'Payment was declined.',
                $intent['id'],
            );
        }

        // succeeded / processing / requires_capture → leave pending for webhook.
        SystemEvent::record('autopay.collect.submitted', $contract, [
            'autopay_attempt_id' => $attempt->id,
            'stripe_payment_intent_id' => $intent['id'],
            'stripe_status' => $status,
        ]);

        return $attempt->fresh() ?? $attempt;
    }

    private function failAttempt(
        AutopayAttempt $attempt,
        string $failureCode,
        ?string $declineCode,
        string $message,
        ?string $piId = null,
    ): AutopayAttempt {
        $attempt->forceFill([
            'status' => AutopayAttemptStatus::Failed,
            'failure_code' => $failureCode,
            'decline_code' => $declineCode,
            'failure_message' => $message,
            'stripe_payment_intent_id' => $piId ?? $attempt->stripe_payment_intent_id,
            'resolved_at' => now(),
        ])->save();

        SystemEvent::record('autopay.collect.failed', $attempt->contract, [
            'autopay_attempt_id' => $attempt->id,
            'failure_code' => $failureCode,
            'decline_code' => $declineCode,
            'stripe_payment_intent_id' => $attempt->stripe_payment_intent_id,
        ]);

        return $attempt->fresh() ?? $attempt;
    }

    private function hasPendingAttempt(int $contractId): bool
    {
        return AutopayAttempt::query()
            ->where('contract_id', $contractId)
            ->where('status', AutopayAttemptStatus::Pending)
            ->exists();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Charge>
     */
    private function openDueCharges(Contract $contract)
    {
        $today = Carbon::today()->toDateString();

        return $contract->charges()
            ->with('allocations')
            ->where('due_date', '<=', $today)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Charge $charge): bool => bccomp($charge->openAmount(), '0', 2) > 0)
            ->values();
    }

    private function declineCodeFromException(ApiErrorException $e): ?string
    {
        $body = $e->getJsonBody();
        $decline = $body['error']['decline_code'] ?? null;

        return is_string($decline) && $decline !== '' ? $decline : null;
    }

    private function paymentIntentIdFromException(ApiErrorException $e): ?string
    {
        $body = $e->getJsonBody();
        $pi = $body['error']['payment_intent']['id'] ?? null;

        return is_string($pi) && $pi !== '' ? $pi : null;
    }
}
