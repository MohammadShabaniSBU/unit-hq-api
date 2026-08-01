<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AutopayAttemptStatus;
use App\Enums\AutopayAttemptTrigger;
use App\Enums\ChargeType;
use App\Enums\LogChannel;
use App\Enums\PaymentInstrumentType;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Enums\PaymentRequestStatus;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\Activity;
use App\Models\Allocation;
use App\Models\AutopayAttempt;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderAccount;
use App\Models\PaymentRequest;
use App\Models\Site;
use App\Models\StripeWebhookEvent;
use App\Models\SystemEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Payments\MinorUnits;
use App\Support\Payments\StripeClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class WebhookLedgerTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Contact $contact;

    private LegalEntity $entity;

    private PaymentProviderAccount $account;

    private Contract $contract;

    private Charge $olderCharge;

    private Charge $newerCharge;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);

        $this->contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        $this->entity = LegalEntity::factory()->create(['legal_name' => 'Payco SL']);
        $this->account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $this->entity->id,
            'secret_key' => 'sk_test_ledger',
            'publishable_key' => 'pk_test_ledger',
        ]);

        $site = Site::factory()->create(['legal_entity_id' => $this->entity->id]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $this->employee->id,
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $this->contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'currency' => 'EUR',
        ]);
        $this->contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $this->contract->start_date,
            'effective_to' => null,
        ]);

        $this->olderCharge = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
            'description' => 'Older overdue',
        ]);
        $this->newerCharge = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '80.00',
            'net_amount' => '80.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-15',
            'description' => 'Newer overdue',
        ]);
    }

    public function test_exactly_once_under_replay(): void
    {
        $request = $this->makePaymentRequest(
            chargeIds: [$this->olderCharge->id, $this->newerCharge->id],
            amount: '180.00',
            status: PaymentRequestStatus::Processing,
        );

        $piId = 'pi_replay_1';
        $payload = $this->paymentIntentPayload(
            piId: $piId,
            amountMinor: MinorUnits::toMinor('180.00', 'EUR'),
            metadata: ['payment_request_id' => (string) $request->id],
        );

        $event = $this->storeEvent('evt_replay_1', 'payment_intent.succeeded', $payload);
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(2, Allocation::query()->count());
        $this->assertSame(PaymentRequestStatus::Paid, $request->fresh()->status);

        // Layer 1: processed skip — same event row re-handled does nothing.
        $event->update(['processing_status' => 'pending', 'processed_at' => null]);
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(2, Allocation::query()->count());

        // Layer 2: bypass event dedup — new event row, same PI id → unique key.
        $dup = $this->storeEvent('evt_replay_2', 'payment_intent.succeeded', $payload);
        (new ProcessStripeWebhookEvent($dup->id))->handle();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(2, Allocation::query()->count());

        $payment = Payment::query()->firstOrFail();
        $this->assertSame($piId, $payment->idempotency_key);
        $this->assertSame($piId, $payment->stripe_payment_intent_id);
        $this->assertSame(PaymentMethodEnum::StripeCard, $payment->method);

        $activity = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'payment.recorded')
            ->where('subject_id', $payment->id)
            ->get();
        $this->assertCount(1, $activity);
        $this->assertSame('stripe', $activity->first()->properties->get('rail'));
        $this->assertNull($activity->first()->causer_id);
    }

    public function test_allocation_targeted_then_oldest_capped(): void
    {
        // Partially pay the newer (targeted) charge first via manual cash.
        Payment::query()->create([
            'contract_id' => $this->contract->id,
            'amount' => '30.00',
            'currency' => 'EUR',
            'method' => PaymentMethodEnum::Cash,
            'received_on' => '2026-08-01',
            'reference' => null,
            'stripe_payment_intent_id' => null,
            'idempotency_key' => 'manual:partial-target',
            'reversal_of_payment_id' => null,
        ]);
        Allocation::query()->create([
            'payment_id' => Payment::query()->where('idempotency_key', 'manual:partial-target')->value('id'),
            'charge_id' => $this->newerCharge->id,
            'amount' => '30.00',
        ]);

        // Target only the newer charge; PI covers remaining open on target + surplus.
        // Newer open = 50; older open = 100; PI = 120 → 50 to newer, 70 to older, 0 credit.
        // Then overpay case: second scenario with larger PI.
        $request = $this->makePaymentRequest(
            chargeIds: [$this->newerCharge->id],
            amount: '50.00',
            status: PaymentRequestStatus::Pending,
        );

        $piId = 'pi_alloc_1';
        $event = $this->storeEvent(
            'evt_alloc_1',
            'payment_intent.succeeded',
            $this->paymentIntentPayload(
                piId: $piId,
                amountMinor: MinorUnits::toMinor('120.00', 'EUR'),
                metadata: ['payment_request_id' => (string) $request->id],
            ),
        );
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $stripePayment = Payment::query()->where('idempotency_key', $piId)->firstOrFail();
        $allocs = Allocation::query()
            ->where('payment_id', $stripePayment->id)
            ->get()
            ->keyBy('charge_id');

        $this->assertSame('50.00', (string) $allocs[$this->newerCharge->id]->amount);
        $this->assertSame('70.00', (string) $allocs[$this->olderCharge->id]->amount);
        $this->assertSame('0.00', $this->newerCharge->fresh()->openAmount());
        $this->assertSame('30.00', $this->olderCharge->fresh()->openAmount());

        // Surplus beyond all open → computed credit (unallocated remainder).
        $extraCharge = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '20.00',
            'net_amount' => '20.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);
        $request2 = $this->makePaymentRequest(
            chargeIds: [$extraCharge->id],
            amount: '20.00',
        );
        $piOver = 'pi_alloc_over';
        $event2 = $this->storeEvent(
            'evt_alloc_over',
            'payment_intent.succeeded',
            $this->paymentIntentPayload(
                piId: $piOver,
                amountMinor: MinorUnits::toMinor('80.00', 'EUR'),
                metadata: ['payment_request_id' => (string) $request2->id],
            ),
        );
        (new ProcessStripeWebhookEvent($event2->id))->handle();

        $overPay = Payment::query()->where('idempotency_key', $piOver)->firstOrFail();
        $overAllocated = (string) Allocation::query()
            ->where('payment_id', $overPay->id)
            ->sum('amount');
        // 20 target + 30 remaining older = 50 allocated; 30 credit.
        $this->assertSame('50.00', number_format((float) $overAllocated, 2, '.', ''));
        $credit = bcsub((string) $overPay->amount, $overAllocated, 2);
        $this->assertSame('30.00', $credit);
        $this->assertSame('0.00', $this->olderCharge->fresh()->openAmount());
        $this->assertSame('0.00', $extraCharge->fresh()->openAmount());
    }

    public function test_failed_intent_records_no_money(): void
    {
        $method = PaymentMethod::factory()->create([
            'contact_id' => $this->contact->id,
            'payment_provider_account_id' => $this->account->id,
            'type' => PaymentInstrumentType::StripeCard,
            'stripe_pm_id' => 'pm_fail_1',
            'is_default' => true,
        ]);

        $attempt = AutopayAttempt::factory()->create([
            'contract_id' => $this->contract->id,
            'payment_method_id' => $method->id,
            'charge_ids' => [$this->olderCharge->id],
            'amount' => '100.00',
            'currency' => 'EUR',
            'status' => AutopayAttemptStatus::Pending,
            'triggered_by' => AutopayAttemptTrigger::Manual,
            'stripe_payment_intent_id' => 'pi_fail_1',
        ]);

        $request = $this->makePaymentRequest(
            chargeIds: [$this->newerCharge->id],
            amount: '80.00',
            status: PaymentRequestStatus::Processing,
        );

        $payload = $this->paymentIntentPayload(
            piId: 'pi_fail_1',
            amountMinor: MinorUnits::toMinor('100.00', 'EUR'),
            metadata: [
                'autopay_attempt_id' => (string) $attempt->id,
                'payment_request_id' => (string) $request->id,
            ],
            status: 'requires_payment_method',
            lastError: [
                'code' => 'card_declined',
                'decline_code' => 'insufficient_funds',
                'message' => 'Your card has insufficient funds.',
            ],
        );

        $event = $this->storeEvent('evt_fail_1', 'payment_intent.payment_failed', $payload);
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Allocation::query()->count());

        $attempt = $attempt->fresh();
        $this->assertSame(AutopayAttemptStatus::Failed, $attempt->status);
        $this->assertSame('card_declined', $attempt->failure_code);
        $this->assertSame('insufficient_funds', $attempt->decline_code);
        $this->assertSame('Your card has insufficient funds.', $attempt->failure_message);
        $this->assertNotNull($attempt->resolved_at);

        // Request flipped back to pending (retryable).
        $this->assertSame(PaymentRequestStatus::Pending, $request->fresh()->status);

        $this->assertTrue(
            SystemEvent::query()->where('event', 'stripe.payment_intent.failed')->exists()
        );
        $this->assertSame('processed', $event->fresh()->processing_status);
    }

    public function test_setup_creates_method_idempotently(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('retrievePaymentMethod')
                ->with('sk_test_ledger', 'pm_setup_ledger')
                ->andReturn([
                    'id' => 'pm_setup_ledger',
                    'type' => 'card',
                    'card' => [
                        'brand' => 'visa',
                        'last4' => '4242',
                        'exp_month' => 12,
                        'exp_year' => 2030,
                    ],
                ]);
        });

        $payload = [
            'id' => 'evt_setup_ledger',
            'object' => 'event',
            'type' => 'setup_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'seti_ledger_1',
                    'object' => 'setup_intent',
                    'payment_method' => 'pm_setup_ledger',
                    'metadata' => [
                        'contact_id' => (string) $this->contact->id,
                        'payment_provider_account_id' => (string) $this->account->id,
                    ],
                ],
            ],
        ];

        $event = $this->storeEvent('evt_setup_ledger_1', 'setup_intent.succeeded', $payload);
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $this->assertSame(1, PaymentMethod::query()->count());
        $method = PaymentMethod::query()->firstOrFail();
        $this->assertSame('pm_setup_ledger', $method->stripe_pm_id);
        $this->assertTrue($method->is_default);

        // Replay with a new event row, same PM id.
        $dup = $this->storeEvent('evt_setup_ledger_2', 'setup_intent.succeeded', $payload);
        (new ProcessStripeWebhookEvent($dup->id))->handle();

        $this->assertSame(1, PaymentMethod::query()->count());
        $this->assertSame('processed', $dup->fresh()->processing_status);
    }

    public function test_orphans_and_refunds_alert_only(): void
    {
        $paymentsBefore = Payment::query()->count();

        $orphanPayload = $this->paymentIntentPayload(
            piId: 'pi_orphan_1',
            amountMinor: MinorUnits::toMinor('50.00', 'EUR'),
            metadata: [],
        );
        $orphanEvent = $this->storeEvent(
            'evt_orphan_1',
            'payment_intent.succeeded',
            $orphanPayload,
        );
        (new ProcessStripeWebhookEvent($orphanEvent->id))->handle();

        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertTrue(
            SystemEvent::query()->where('event', 'stripe.orphan_intent')->exists()
        );
        $this->assertSame('processed', $orphanEvent->fresh()->processing_status);

        $refundPayload = [
            'id' => 'evt_refund_1',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_refund_1',
                    'object' => 'charge',
                    'payment_intent' => 'pi_somewhere',
                    'amount_refunded' => 5000,
                    'currency' => 'eur',
                ],
            ],
        ];
        $refundEvent = $this->storeEvent('evt_refund_1', 'charge.refunded', $refundPayload);
        (new ProcessStripeWebhookEvent($refundEvent->id))->handle();

        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertSame(0, Payment::query()->where('amount', '<', 0)->count());
        $this->assertTrue(
            SystemEvent::query()->where('event', 'stripe.refund_external')->exists()
        );
        $this->assertSame('processed', $refundEvent->fresh()->processing_status);
    }

    public function test_out_of_order_and_unknown_safe(): void
    {
        // Succeeded arrives while request is still pending (before processing flip).
        $request = $this->makePaymentRequest(
            chargeIds: [$this->olderCharge->id],
            amount: '100.00',
            status: PaymentRequestStatus::Pending,
        );

        $event = $this->storeEvent(
            'evt_ooo_1',
            'payment_intent.succeeded',
            $this->paymentIntentPayload(
                piId: 'pi_ooo_1',
                amountMinor: MinorUnits::toMinor('100.00', 'EUR'),
                metadata: ['payment_request_id' => (string) $request->id],
            ),
        );
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $this->assertSame(PaymentRequestStatus::Paid, $request->fresh()->status);
        $this->assertSame(1, Payment::query()->count());
        $this->assertNotNull($request->fresh()->paid_payment_id);

        $unknown = $this->storeEvent('evt_unknown_1', 'customer.updated', [
            'id' => 'evt_unknown_1',
            'object' => 'event',
            'type' => 'customer.updated',
            'data' => ['object' => ['id' => 'cus_x']],
        ]);
        (new ProcessStripeWebhookEvent($unknown->id))->handle();

        $this->assertSame('processed', $unknown->fresh()->processing_status);
        $this->assertTrue(
            SystemEvent::query()->where('event', 'stripe.webhook.unknown')->exists()
        );
        // No extra ledger writes from unknown.
        $this->assertSame(1, Payment::query()->count());
    }

    /**
     * @param  list<int>  $chargeIds
     */
    private function makePaymentRequest(
        array $chargeIds,
        string $amount,
        PaymentRequestStatus $status = PaymentRequestStatus::Pending,
    ): PaymentRequest {
        return PaymentRequest::factory()->create([
            'contract_id' => $this->contract->id,
            'payment_provider_account_id' => $this->account->id,
            'charge_ids' => $chargeIds,
            'amount' => $amount,
            'currency' => 'EUR',
            'status' => $status,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeEvent(
        string $stripeEventId,
        string $eventType,
        array $payload,
    ): StripeWebhookEvent {
        return StripeWebhookEvent::query()->create([
            'payment_provider_account_id' => $this->account->id,
            'stripe_event_id' => $stripeEventId,
            'event_type' => $eventType,
            'payload' => $payload,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);
    }

    /**
     * @param  array<string, string>  $metadata
     * @param  array<string, string>|null  $lastError
     * @return array<string, mixed>
     */
    private function paymentIntentPayload(
        string $piId,
        int $amountMinor,
        array $metadata,
        string $status = 'succeeded',
        ?array $lastError = null,
    ): array {
        $object = [
            'id' => $piId,
            'object' => 'payment_intent',
            'amount' => $amountMinor,
            'currency' => 'eur',
            'status' => $status,
            'metadata' => $metadata,
        ];

        if ($lastError !== null) {
            $object['last_payment_error'] = $lastError;
        }

        return [
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $status === 'succeeded'
                ? 'payment_intent.succeeded'
                : 'payment_intent.payment_failed',
            'data' => ['object' => $object],
        ];
    }
}
