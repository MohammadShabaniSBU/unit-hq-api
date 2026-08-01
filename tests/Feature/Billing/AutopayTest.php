<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AutopayAttemptStatus;
use App\Enums\AutopayAttemptTrigger;
use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\BillingRunTrigger;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Enums\MoveOutSettlement;
use App\Enums\PaymentInstrumentType;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\Activity;
use App\Models\Allocation;
use App\Models\AutopayAttempt;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderAccount;
use App\Models\Setting;
use App\Models\Site;
use App\Models\StripeCustomer;
use App\Models\StripeWebhookEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Billing\BillingRunEngine;
use App\Support\Payments\AutopayCollector;
use App\Support\Payments\MinorUnits;
use App\Support\Payments\StripeClient;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Exception\CardException;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class AutopayTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Contact $contact;

    private LegalEntity $entity;

    private PaymentProviderAccount $account;

    private Site $site;

    private UnitClass $unitClass;

    private Contract $contract;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);

        $this->contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        $country = Country::factory()->create(['code' => 'ES']);
        $this->entity = LegalEntity::factory()->create(['legal_name' => 'Payco SL']);
        $this->account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $this->entity->id,
            'secret_key' => 'sk_test_autopay',
            'publishable_key' => 'pk_test_autopay',
        ]);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => $this->entity->id,
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '100.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $this->unitClass->update(['current_price_id' => $price->id]);

        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $this->contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'billing_interval' => BillingInterval::Month,
            'billing_interval_count' => 1,
            'billing_anchor_model' => BillingAnchorModel::Anniversary,
            'billing_anchor_date' => '2026-01-15',
            'move_in_date' => '2026-01-15',
            'billed_through' => '2026-07-15',
            'start_date' => '2026-01-15',
            'deposit_amount' => '0.00',
            'notice_period_days' => 14,
            'move_out_settlement' => MoveOutSettlement::None,
        ]);
        $this->contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
            'tax_rate_snapshot' => '0.00',
        ]);

        $this->method = PaymentMethod::factory()->default()->create([
            'contact_id' => $this->contact->id,
            'payment_provider_account_id' => $this->account->id,
            'type' => PaymentInstrumentType::StripeCard,
            'stripe_pm_id' => 'pm_autopay_1',
            'display_label' => 'Visa ···4242',
        ]);

        StripeCustomer::query()->create([
            'contact_id' => $this->contact->id,
            'payment_provider_account_id' => $this->account->id,
            'stripe_customer_id' => 'cus_autopay_1',
        ]);

        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: '0.00',
            moveOutSettlement: MoveOutSettlement::None->value,
            turnoverHoldDays: 0,
            billingHorizonDays: 0,
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_run_then_collect_then_webhook_settles(): void
    {
        $this->mockStripeSuccess('pi_spine_1');

        $this->putJson("/api/contracts/{$this->contract->id}/autopay", [
            'enabled' => true,
            'payment_method_id' => $this->method->id,
        ])->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'contract.autopay.updated')
                ->exists()
        );

        $run = (new BillingRunEngine)->run(
            BillingRunTrigger::Manual,
            contractId: $this->contract->id,
        );

        $attempt = AutopayAttempt::query()
            ->where('contract_id', $this->contract->id)
            ->where('billing_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(AutopayAttemptStatus::Pending, $attempt->status);
        $this->assertSame(AutopayAttemptTrigger::BillingRun, $attempt->triggered_by);
        $this->assertSame('pi_spine_1', $attempt->stripe_payment_intent_id);
        $this->assertNotEmpty($attempt->charge_ids);

        $chargeIds = array_map('intval', $attempt->charge_ids);
        $openBefore = Charge::query()->whereIn('id', $chargeIds)->get()
            ->sum(fn (Charge $c) => (float) $c->openAmount());
        $this->assertGreaterThan(0, $openBefore);

        $event = $this->storeEvent(
            'evt_autopay_ok_1',
            'payment_intent.succeeded',
            $this->paymentIntentPayload(
                piId: 'pi_spine_1',
                amountMinor: MinorUnits::toMinor((string) $attempt->amount, 'EUR'),
                metadata: ['autopay_attempt_id' => (string) $attempt->id],
            ),
        );
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $attempt = $attempt->fresh();
        $this->assertSame(AutopayAttemptStatus::Succeeded, $attempt->status);
        $this->assertNotNull($attempt->resolved_at);

        $payment = Payment::query()->where('stripe_payment_intent_id', 'pi_spine_1')->firstOrFail();
        $this->assertSame((string) $attempt->amount, (string) $payment->amount);

        foreach ($chargeIds as $chargeId) {
            $this->assertSame('0.00', Charge::query()->findOrFail($chargeId)->openAmount());
        }

        $allocated = (string) Allocation::query()->where('payment_id', $payment->id)->sum('amount');
        $this->assertSame((string) $attempt->amount, number_format((float) $allocated, 2, '.', ''));
    }

    public function test_single_flight_under_race(): void
    {
        $this->enableAutopay();
        $this->seedDueCharge('50.00');

        AutopayAttempt::factory()->create([
            'contract_id' => $this->contract->id,
            'payment_method_id' => $this->method->id,
            'charge_ids' => [Charge::query()->firstOrFail()->id],
            'amount' => '50.00',
            'currency' => 'EUR',
            'status' => AutopayAttemptStatus::Pending,
            'triggered_by' => AutopayAttemptTrigger::Sweep,
        ]);

        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('createPaymentIntent')->never();
        });

        $created = app(AutopayCollector::class)->collect(
            AutopayAttemptTrigger::Sweep,
            [(int) $this->contract->id],
        );

        $this->assertSame([], $created);
        $this->assertSame(1, AutopayAttempt::query()
            ->where('contract_id', $this->contract->id)
            ->where('status', AutopayAttemptStatus::Pending)
            ->count());

        if (config('database.default') === 'pgsql'
            || \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql'
        ) {
            $this->expectException(UniqueConstraintViolationException::class);
            AutopayAttempt::factory()->create([
                'contract_id' => $this->contract->id,
                'payment_method_id' => $this->method->id,
                'charge_ids' => [1],
                'amount' => '10.00',
                'currency' => 'EUR',
                'status' => AutopayAttemptStatus::Pending,
                'triggered_by' => AutopayAttemptTrigger::Manual,
            ]);
        } else {
            $this->markTestSkipped('Partial unique aa_open_idx is PostgreSQL-only.');
        }
    }

    public function test_declines_recorded_no_money(): void
    {
        $this->enableAutopay();
        $charge = $this->seedDueCharge('80.00');

        // Sync decline via API exception.
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('createPaymentIntent')
                ->once()
                ->andThrow(CardException::factory(
                    'Your card was declined.',
                    402,
                    '{"error":{"type":"card_error","code":"card_declined","decline_code":"generic_decline","message":"Your card was declined.","payment_intent":{"id":"pi_sync_fail"}}}',
                    [
                        'error' => [
                            'type' => 'card_error',
                            'code' => 'card_declined',
                            'decline_code' => 'generic_decline',
                            'message' => 'Your card was declined.',
                            'payment_intent' => ['id' => 'pi_sync_fail'],
                        ],
                    ],
                    [],
                    'card_declined',
                    'generic_decline',
                ));
        });

        $syncAttempts = app(AutopayCollector::class)->collect(
            AutopayAttemptTrigger::Manual,
            [(int) $this->contract->id],
        );

        $this->assertCount(1, $syncAttempts);
        $sync = $syncAttempts[0];
        $this->assertSame(AutopayAttemptStatus::Failed, $sync->status);
        $this->assertSame('card_declined', $sync->failure_code);
        $this->assertSame('generic_decline', $sync->decline_code);
        $this->assertSame(0, Payment::query()->count());

        // Async webhook decline on a fresh pending attempt.
        $pending = AutopayAttempt::factory()->create([
            'contract_id' => $this->contract->id,
            'payment_method_id' => $this->method->id,
            'charge_ids' => [$charge->id],
            'amount' => '80.00',
            'currency' => 'EUR',
            'status' => AutopayAttemptStatus::Pending,
            'triggered_by' => AutopayAttemptTrigger::Manual,
            'stripe_payment_intent_id' => 'pi_async_fail',
        ]);

        $event = $this->storeEvent(
            'evt_autopay_fail_1',
            'payment_intent.payment_failed',
            $this->paymentIntentPayload(
                piId: 'pi_async_fail',
                amountMinor: MinorUnits::toMinor('80.00', 'EUR'),
                metadata: ['autopay_attempt_id' => (string) $pending->id],
                status: 'requires_payment_method',
                lastError: [
                    'code' => 'card_declined',
                    'decline_code' => 'insufficient_funds',
                    'message' => 'Your card has insufficient funds.',
                ],
            ),
        );
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $pending = $pending->fresh();
        $this->assertSame(AutopayAttemptStatus::Failed, $pending->status);
        $this->assertSame('card_declined', $pending->failure_code);
        $this->assertSame('insufficient_funds', $pending->decline_code);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('80.00', $charge->fresh()->openAmount());
    }

    public function test_cross_entity_card_refused(): void
    {
        $otherEntity = LegalEntity::factory()->create(['legal_name' => 'Other SL']);
        $otherAccount = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $otherEntity->id,
            'secret_key' => 'sk_other',
        ]);
        $foreignMethod = PaymentMethod::factory()->create([
            'contact_id' => $this->contact->id,
            'payment_provider_account_id' => $otherAccount->id,
            'type' => PaymentInstrumentType::StripeCard,
            'stripe_pm_id' => 'pm_foreign',
            'display_label' => 'Visa ···9999',
            'is_default' => false,
        ]);

        $this->putJson("/api/contracts/{$this->contract->id}/autopay", [
            'enabled' => true,
            'payment_method_id' => $foreignMethod->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method_id']);

        $this->assertFalse((bool) $this->contract->fresh()->autopay_enabled);
    }

    public function test_sweep_catches_late_enables(): void
    {
        // Bill first without autopay — charges land, no attempt.
        (new BillingRunEngine)->run(
            BillingRunTrigger::Manual,
            contractId: $this->contract->id,
        );

        $this->assertSame(0, AutopayAttempt::query()->count());
        $this->assertGreaterThan(0, Charge::query()->where('contract_id', $this->contract->id)->count());

        $this->mockStripeSuccess('pi_sweep_1');
        $this->enableAutopay();

        $this->artisan('autopay:collect', [
            '--trigger' => 'sweep',
            '--contract' => $this->contract->id,
        ])->assertSuccessful();

        $attempt = AutopayAttempt::query()
            ->where('contract_id', $this->contract->id)
            ->firstOrFail();

        $this->assertSame(AutopayAttemptTrigger::Sweep, $attempt->triggered_by);
        $this->assertSame('pi_sweep_1', $attempt->stripe_payment_intent_id);
        $this->assertContains(
            $attempt->status,
            [AutopayAttemptStatus::Pending, AutopayAttemptStatus::Failed],
        );
        $this->assertSame(AutopayAttemptStatus::Pending, $attempt->status);
    }

    private function enableAutopay(): void
    {
        $this->contract->forceFill([
            'autopay_enabled' => true,
            'payment_method_id' => $this->method->id,
        ])->save();
    }

    private function seedDueCharge(string $amount): Charge
    {
        return Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => $amount,
            'net_amount' => $amount,
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);
    }

    private function mockStripeSuccess(string $piId): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock) use ($piId): void {
            $mock->shouldReceive('createPaymentIntent')
                ->once()
                ->andReturn([
                    'id' => $piId,
                    'client_secret' => null,
                    'status' => 'succeeded',
                    'last_payment_error' => null,
                ]);
        });
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
