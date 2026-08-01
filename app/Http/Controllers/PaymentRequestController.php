<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentRequestStatus;
use App\Http\Resources\PaymentRequestResource;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\PaymentRequest;
use App\Support\Payments\PaymentsNotConfigured;
use App\Support\Payments\ProviderAccountResolver;
use App\Support\Payments\StripeClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;

/**
 * Staff endpoints for tokenised payment links (S06-02).
 */
class PaymentRequestController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function index(Contract $contract): JsonResponse
    {
        $requests = $contract->paymentRequests()
            ->orderByDesc('id')
            ->get();

        return $this->success(
            PaymentRequestResource::collection($requests)->resolve(),
            'Payment requests retrieved successfully.'
        );
    }

    public function store(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'charge_ids' => ['sometimes', 'array', 'min:1'],
            'charge_ids.*' => ['integer'],
            'save_card' => ['sometimes', 'boolean'],
        ]);

        try {
            $account = ProviderAccountResolver::forContract($contract);
        } catch (PaymentsNotConfigured $e) {
            throw ValidationException::withMessages([
                'payments' => [$e->getMessage()],
            ]);
        }

        $charges = $this->resolveChargeSet($contract, $validated['charge_ids'] ?? null);

        $amount = '0.00';
        $currency = null;
        $chargeIds = [];

        foreach ($charges as $charge) {
            $open = $charge->openAmount();
            if (bccomp($open, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'charge_ids' => ["Charge {$charge->id} is not open."],
                ]);
            }

            if ($currency === null) {
                $currency = (string) $charge->currency;
            } elseif ($currency !== (string) $charge->currency) {
                throw ValidationException::withMessages([
                    'charge_ids' => ['All charges must share the same currency.'],
                ]);
            }

            $amount = bcadd($amount, $open, 2);
            $chargeIds[] = (int) $charge->id;
        }

        if ($chargeIds === [] || bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'charge_ids' => ['No open due or overdue charges to request payment for.'],
            ]);
        }

        $ttlDays = max(1, (int) config('payments.payment_request_ttl_days', 7));

        $paymentRequest = PaymentRequest::query()->create([
            'token' => Str::random(64),
            'contract_id' => $contract->id,
            'payment_provider_account_id' => $account->id,
            'charge_ids' => $chargeIds,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentRequestStatus::Pending,
            'expires_at' => now()->addDays($ttlDays),
            'save_card_requested' => (bool) ($validated['save_card'] ?? false),
            'created_by' => $request->user()?->id,
        ]);

        return $this->created(
            PaymentRequestResource::make($paymentRequest)->resolve(),
            'Payment request created successfully.'
        );
    }

    public function cancel(PaymentRequest $paymentRequest): JsonResponse
    {
        if ($paymentRequest->status !== PaymentRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Only pending payment requests can be cancelled.'],
            ]);
        }

        if ($paymentRequest->isExpired()) {
            throw ValidationException::withMessages([
                'status' => ['Expired payment requests cannot be cancelled.'],
            ]);
        }

        $paymentRequest->status = PaymentRequestStatus::Cancelled;
        $paymentRequest->save();

        if ($paymentRequest->stripe_payment_intent_id !== null) {
            $account = $paymentRequest->paymentProviderAccount;
            $secretKey = $account?->secret_key;
            if ($secretKey !== null && $secretKey !== '') {
                try {
                    $this->stripe->cancelPaymentIntent(
                        $secretKey,
                        $paymentRequest->stripe_payment_intent_id,
                    );
                } catch (ApiErrorException) {
                    // Best-effort — local cancel still stands.
                }
            }
        }

        return $this->success(
            PaymentRequestResource::make($paymentRequest->fresh())->resolve(),
            'Payment request cancelled successfully.'
        );
    }

    /**
     * @param  list<int>|null  $chargeIds
     * @return \Illuminate\Support\Collection<int, Charge>
     */
    private function resolveChargeSet(Contract $contract, ?array $chargeIds)
    {
        $today = Carbon::today()->toDateString();

        if ($chargeIds === null) {
            return $contract->charges()
                ->with('allocations')
                ->where('due_date', '<=', $today)
                ->orderBy('due_date')
                ->orderBy('id')
                ->get()
                ->filter(fn (Charge $charge): bool => bccomp($charge->openAmount(), '0', 2) > 0)
                ->values();
        }

        $ids = array_values(array_unique(array_map('intval', $chargeIds)));

        $charges = $contract->charges()
            ->with('allocations')
            ->whereIn('id', $ids)
            ->get();

        if ($charges->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'charge_ids' => ['One or more charges do not belong to this contract.'],
            ]);
        }

        return $charges->sortBy([
            ['due_date', 'asc'],
            ['id', 'asc'],
        ])->values();
    }
}
