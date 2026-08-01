<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Models\StripeCustomer;
use App\Support\Payments\MinorUnits;
use App\Support\Payments\StripeClient;
use App\Support\Payments\StripeCustomers;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;

/**
 * Token-authenticated public pay page API (S06-02).
 * Never writes the ledger — invariant 11; S06-03 owns payment confirmation.
 */
class PublicPaymentController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function show(string $token): JsonResponse
    {
        $paymentRequest = $this->findByToken($token);

        return $this->success(
            $this->publicPayload($paymentRequest),
            'Payment request retrieved successfully.'
        );
    }

    public function intent(string $token): JsonResponse
    {
        $paymentRequest = $this->findByToken($token);

        if ($paymentRequest->status === PaymentRequestStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['This payment link has been cancelled.'],
            ]);
        }

        if ($paymentRequest->status === PaymentRequestStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => ['This payment link has already been paid.'],
            ]);
        }

        if ($paymentRequest->isExpired()) {
            throw ValidationException::withMessages([
                'status' => ['This payment link has expired.'],
            ]);
        }

        if ($paymentRequest->hasAmountMismatch()) {
            throw ValidationException::withMessages([
                'amount' => ['The targeted charges no longer match this payment link. Request a new link.'],
            ]);
        }

        $account = $paymentRequest->paymentProviderAccount;
        $secretKey = $account->secret_key;
        if ($secretKey === null || $secretKey === '') {
            throw ValidationException::withMessages([
                'payments' => ['Payment provider account has no secret key.'],
            ]);
        }

        try {
            if ($paymentRequest->stripe_payment_intent_id !== null) {
                $intent = $this->stripe->retrievePaymentIntent(
                    $secretKey,
                    $paymentRequest->stripe_payment_intent_id,
                );

                return $this->success([
                    'client_secret' => $intent['client_secret'],
                    'payment_intent_id' => $intent['id'],
                    'publishable_key' => $account->publishable_key,
                ], 'Payment intent retrieved successfully.');
            }

            $contract = $paymentRequest->contract;
            $customerId = $this->resolveCustomerId($paymentRequest);

            $params = [
                'amount' => MinorUnits::toMinor((string) $paymentRequest->amount, $paymentRequest->currency),
                'currency' => $paymentRequest->currency,
                'customer' => $customerId,
                'metadata' => [
                    'payment_request_id' => (string) $paymentRequest->id,
                    'contract_id' => (string) $contract->id,
                ],
            ];

            if ($paymentRequest->save_card_requested) {
                $params['setup_future_usage'] = 'off_session';
                if ($customerId === null) {
                    $customer = StripeCustomers::for($contract->contact, $account);
                    $params['customer'] = $customer->stripe_customer_id;
                }
            }

            $intent = $this->stripe->createPaymentIntent($secretKey, $params);

            $paymentRequest->stripe_payment_intent_id = $intent['id'];
            $paymentRequest->save();
        } catch (ApiErrorException $e) {
            throw ValidationException::withMessages([
                'stripe' => [$e->getMessage()],
            ]);
        }

        return $this->success([
            'client_secret' => $intent['client_secret'],
            'payment_intent_id' => $intent['id'],
            'publishable_key' => $account->publishable_key,
        ], 'Payment intent created successfully.');
    }

    private function findByToken(string $token): PaymentRequest
    {
        return PaymentRequest::query()
            ->where('token', $token)
            ->with([
                'contract.contact',
                'paymentProviderAccount.legalEntity',
            ])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPayload(PaymentRequest $paymentRequest): array
    {
        $contract = $paymentRequest->contract;
        $contact = $contract->contact;
        $account = $paymentRequest->paymentProviderAccount;
        $entity = $account->legalEntity;

        $lines = $paymentRequest->targetedCharges()->map(fn ($charge): array => [
            'charge_type' => $charge->charge_type?->value,
            'period_start' => $charge->period_start?->toDateString(),
            'period_end' => $charge->period_end?->toDateString(),
            'due_date' => $charge->due_date?->toDateString(),
            'open_amount' => $charge->openAmount(),
            'currency' => $charge->currency,
        ])->values()->all();

        $status = $paymentRequest->status?->value;
        $expired = $paymentRequest->isExpired();

        return [
            'status' => $status,
            'expired' => $expired,
            'amount' => (string) $paymentRequest->amount,
            'currency' => $paymentRequest->currency,
            'amount_mismatch' => $paymentRequest->hasAmountMismatch(),
            'current_open_total' => $paymentRequest->currentOpenTotal(),
            'expires_at' => $paymentRequest->expires_at?->toDateTimeString(),
            'save_card_requested' => (bool) $paymentRequest->save_card_requested,
            'publishable_key' => $account->publishable_key,
            'entity_name' => $entity?->trading_name ?: $entity?->legal_name,
            'contact_first_name' => $contact?->first_name,
            'lines' => $lines,
        ];
    }

    private function resolveCustomerId(PaymentRequest $paymentRequest): ?string
    {
        $customer = StripeCustomer::query()
            ->where('contact_id', $paymentRequest->contract->contact_id)
            ->where('payment_provider_account_id', $paymentRequest->payment_provider_account_id)
            ->first();

        return $customer?->stripe_customer_id;
    }
}
