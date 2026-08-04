<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PaymentMethodResource;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\PaymentMethod;
use App\Support\Payments\PaymentMethods;
use App\Support\Payments\PaymentsNotConfigured;
use App\Support\Payments\ProviderAccountResolver;
use App\Support\Payments\StripeClient;
use App\Support\Payments\StripeCustomers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Saved payment instruments for a contact (S06-01).
 * Local rows are created only via setup_intent.succeeded webhooks.
 */
class ContactPaymentMethodController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function index(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::PaymentView->value, $contact);

        $query = $contact->paymentMethods()->active()->orderByDesc('is_default')->orderByDesc('id');

        if ($request->filled('payment_provider_account_id')) {
            $query->where(
                'payment_provider_account_id',
                $request->integer('payment_provider_account_id'),
            );
        }

        return $this->success(
            PaymentMethodResource::collection($query->get())->resolve(),
            'Payment methods retrieved successfully.'
        );
    }

    public function setup(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::PaymentRecord->value, $contact);

        $validated = $request->validate([
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
        ]);

        $contract = Contract::query()->findOrFail($validated['contract_id']);

        if ((int) $contract->contact_id !== (int) $contact->id) {
            throw ValidationException::withMessages([
                'contract_id' => ['The contract does not belong to this contact.'],
            ]);
        }

        try {
            $account = ProviderAccountResolver::forContract($contract);
        } catch (PaymentsNotConfigured $e) {
            throw ValidationException::withMessages([
                'payments' => [$e->getMessage()],
            ]);
        }

        $secretKey = $account->secret_key;
        if ($secretKey === null || $secretKey === '') {
            throw ValidationException::withMessages([
                'payments' => ['Payment provider account has no secret key.'],
            ]);
        }

        try {
            $customer = StripeCustomers::for($contact, $account);
            $intent = $this->stripe->createSetupIntent($secretKey, [
                'customer' => $customer->stripe_customer_id,
                'usage' => 'off_session',
                'metadata' => [
                    'contact_id' => (string) $contact->id,
                    'payment_provider_account_id' => (string) $account->id,
                    'contract_id' => (string) $contract->id,
                ],
            ]);
        } catch (ApiErrorException $e) {
            throw ValidationException::withMessages([
                'stripe' => [$e->getMessage()],
            ]);
        }

        return $this->success([
            'client_secret' => $intent['client_secret'],
            'publishable_key' => $account->publishable_key,
            'payment_provider_account_id' => $account->id,
            'setup_intent_id' => $intent['id'],
        ], 'Setup intent created successfully.');
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        Gate::authorize(Permission::PaymentRecord->value, $paymentMethod);

        if ($paymentMethod->isArchived()) {
            return $this->error('Payment method is archived.', [], 422);
        }

        $request->validate([
            'is_default' => ['required', 'accepted'],
        ]);

        $updated = PaymentMethods::setDefault($paymentMethod);

        return $this->success(
            PaymentMethodResource::make($updated)->resolve(),
            'Default payment method updated successfully.'
        );
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        Gate::authorize(Permission::PaymentRecord->value, $paymentMethod);

        if ($paymentMethod->isArchived()) {
            return $this->noContent('Payment method already removed.');
        }

        try {
            PaymentMethods::archive($paymentMethod);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'payment_method' => [$e->getMessage()],
            ]);
        }

        return $this->noContent('Payment method removed successfully.');
    }
}
