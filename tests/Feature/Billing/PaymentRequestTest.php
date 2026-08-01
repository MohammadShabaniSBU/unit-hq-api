<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\PaymentRequestStatus;
use App\Models\Allocation;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Payment;
use App\Models\PaymentProviderAccount;
use App\Models\PaymentRequest;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Payments\MinorUnits;
use App\Support\Payments\StripeClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class PaymentRequestTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Contact $contact;

    private LegalEntity $entity;

    private PaymentProviderAccount $account;

    private Contract $contract;

    private Charge $dueCharge;

    private Charge $overdueCharge;

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
        $this->entity = LegalEntity::factory()->create([
            'legal_name' => 'Payco SL',
            'trading_name' => 'Payco Storage',
        ]);
        $this->account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $this->entity->id,
            'secret_key' => 'sk_test_pr',
            'publishable_key' => 'pk_test_pr',
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

        $this->overdueCharge = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);
        $this->dueCharge = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '80.00',
            'net_amount' => '80.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-02',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);
    }

    public function test_create_validates_open_same_currency(): void
    {
        $created = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests", [
            'save_card' => true,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.amount', '180.00')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.save_card_requested', true);

        $this->assertStringStartsWith('/pay/', (string) $created->json('data.url'));
        $this->assertEqualsCanonicalizing(
            [$this->overdueCharge->id, $this->dueCharge->id],
            $created->json('data.charge_ids'),
        );

        // Future-due open charge is excluded from the default set.
        $future = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'amount' => '50.00',
            'net_amount' => '50.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-09-01',
        ]);

        $defaultAgain = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests");
        $defaultAgain->assertCreated();
        $this->assertNotContains($future->id, $defaultAgain->json('data.charge_ids'));

        // Closed charge refused.
        $payment = Payment::factory()->create([
            'contract_id' => $this->contract->id,
            'amount' => '100.00',
            'currency' => 'EUR',
        ]);
        Allocation::factory()->create([
            'payment_id' => $payment->id,
            'charge_id' => $this->overdueCharge->id,
            'amount' => '100.00',
        ]);

        $closed = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests", [
            'charge_ids' => [$this->overdueCharge->id],
        ]);
        $closed->assertStatus(422)->assertJsonValidationErrors(['charge_ids']);

        // Foreign charge refused.
        $other = Charge::factory()->create([
            'amount' => '10.00',
            'net_amount' => '10.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
        ]);
        $foreign = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests", [
            'charge_ids' => [$other->id],
        ]);
        $foreign->assertStatus(422)->assertJsonValidationErrors(['charge_ids']);

        // Mixed currency refused (force divergent currency past the create-time guard).
        $usd = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'amount' => '10.00',
            'net_amount' => '10.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-15',
        ]);
        Charge::query()->whereKey($usd->id)->update(['currency' => 'USD']);

        $mixed = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests", [
            'charge_ids' => [$this->dueCharge->id, $usd->id],
        ]);
        $mixed->assertStatus(422)->assertJsonValidationErrors(['charge_ids']);

        // Payments not configured.
        $this->account->update(['is_active' => false]);
        $unconfigured = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests", [
            'charge_ids' => [$this->dueCharge->id],
        ]);
        $unconfigured->assertStatus(422)->assertJsonValidationErrors(['payments']);
    }

    public function test_public_read_states_and_expiry(): void
    {
        $pending = PaymentRequest::factory()->create([
            'contract_id' => $this->contract->id,
            'payment_provider_account_id' => $this->account->id,
            'charge_ids' => [$this->overdueCharge->id, $this->dueCharge->id],
            'amount' => '180.00',
            'currency' => 'EUR',
            'created_by' => $this->employee->id,
        ]);

        $ok = $this->getJson("/api/pay/{$pending->token}");
        $ok->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.expired', false)
            ->assertJsonPath('data.amount', '180.00')
            ->assertJsonPath('data.entity_name', 'Payco Storage')
            ->assertJsonPath('data.contact_first_name', 'Ada')
            ->assertJsonPath('data.publishable_key', 'pk_test_pr')
            ->assertJsonCount(2, 'data.lines');

        $payload = $ok->json('data');
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('last_name', $payload);
        $this->assertSame('rent', $payload['lines'][0]['charge_type']);

        $expired = PaymentRequest::factory()->expired()->create([
            'contract_id' => $this->contract->id,
            'payment_provider_account_id' => $this->account->id,
            'charge_ids' => [$this->dueCharge->id],
            'amount' => '80.00',
            'currency' => 'EUR',
        ]);
        $this->getJson("/api/pay/{$expired->token}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.expired', true);

        $cancelled = PaymentRequest::factory()->cancelled()->create([
            'contract_id' => $this->contract->id,
            'payment_provider_account_id' => $this->account->id,
            'charge_ids' => [$this->dueCharge->id],
            'amount' => '80.00',
            'currency' => 'EUR',
        ]);
        $this->getJson("/api/pay/{$cancelled->token}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.expired', false);

        $paid = PaymentRequest::factory()->paid()->create([
            'contract_id' => $this->contract->id,
            'payment_provider_account_id' => $this->account->id,
            'charge_ids' => [$this->dueCharge->id],
            'amount' => '80.00',
            'currency' => 'EUR',
        ]);
        $this->getJson("/api/pay/{$paid->token}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->getJson('/api/pay/'.str_repeat('x', 64))->assertNotFound();
    }

    public function test_intent_reentrant_and_recomputes(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('createPaymentIntent')
                ->once()
                ->withArgs(function (string $secret, array $params): bool {
                    return $secret === 'sk_test_pr'
                        && $params['amount'] === 18000
                        && $params['currency'] === 'EUR'
                        && ($params['setup_future_usage'] ?? null) === 'off_session'
                        && isset($params['metadata']['payment_request_id']);
                })
                ->andReturn([
                    'id' => 'pi_reentrant_1',
                    'client_secret' => 'pi_reentrant_1_secret_xxx',
                    'status' => 'requires_payment_method',
                ]);

            $mock->shouldReceive('retrievePaymentIntent')
                ->once()
                ->with('sk_test_pr', 'pi_reentrant_1')
                ->andReturn([
                    'id' => 'pi_reentrant_1',
                    'client_secret' => 'pi_reentrant_1_secret_xxx',
                    'status' => 'requires_payment_method',
                ]);

            $mock->shouldReceive('createCustomer')->andReturn(['id' => 'cus_save_1']);
        });

        $create = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests", [
            'save_card' => true,
        ]);
        $create->assertCreated();
        $token = (string) $create->json('data.token');

        $first = $this->postJson("/api/pay/{$token}/intent");
        $first->assertOk()
            ->assertJsonPath('data.payment_intent_id', 'pi_reentrant_1')
            ->assertJsonPath('data.client_secret', 'pi_reentrant_1_secret_xxx');

        $second = $this->postJson("/api/pay/{$token}/intent");
        $second->assertOk()
            ->assertJsonPath('data.payment_intent_id', 'pi_reentrant_1')
            ->assertJsonPath('data.client_secret', 'pi_reentrant_1_secret_xxx');

        // Stale set: pay one of the targeted charges elsewhere.
        $payment = Payment::factory()->create([
            'contract_id' => $this->contract->id,
            'amount' => '100.00',
            'currency' => 'EUR',
        ]);
        Allocation::factory()->create([
            'payment_id' => $payment->id,
            'charge_id' => $this->overdueCharge->id,
            'amount' => '100.00',
        ]);

        $stale = $this->postJson("/api/pay/{$token}/intent");
        $stale->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $read = $this->getJson("/api/pay/{$token}");
        $read->assertOk()->assertJsonPath('data.amount_mismatch', true);
    }

    public function test_no_ledger_write_from_page(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('createPaymentIntent')->andReturn([
                'id' => 'pi_nolegger_1',
                'client_secret' => 'pi_nolegger_1_secret',
                'status' => 'requires_payment_method',
            ]);
        });

        $create = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests");
        $create->assertCreated();
        $token = (string) $create->json('data.token');

        $beforePayments = Payment::query()->count();
        $beforeAllocations = Allocation::query()->count();

        $this->postJson("/api/pay/{$token}/intent")->assertOk();

        // Simulate client success callback — still no ledger write endpoint.
        $confirm = $this->postJson("/api/pay/{$token}/confirm", [
            'payment_intent_id' => 'pi_nolegger_1',
        ]);
        $this->assertContains($confirm->status(), [404, 405]);

        $this->assertSame($beforePayments, Payment::query()->count());
        $this->assertSame($beforeAllocations, Allocation::query()->count());
        $this->assertSame(
            PaymentRequestStatus::Pending,
            PaymentRequest::query()->where('token', $token)->firstOrFail()->status,
        );
    }

    public function test_minor_units_roundtrip(): void
    {
        $this->assertSame(1000, MinorUnits::toMinor('10.00', 'EUR'));
        $this->assertSame('10.00', MinorUnits::fromMinor(1000, 'EUR'));

        $this->assertSame(18000, MinorUnits::toMinor('180.00', 'eur'));
        $this->assertSame('180.00', MinorUnits::fromMinor(18000, 'EUR'));

        $this->assertSame(1000, MinorUnits::toMinor('1000', 'JPY'));
        $this->assertSame('1000.00', MinorUnits::fromMinor(1000, 'JPY'));

        $this->assertSame(99, MinorUnits::toMinor('0.99', 'EUR'));
        $this->assertSame('0.99', MinorUnits::fromMinor(99, 'EUR'));

        $this->expectException(\InvalidArgumentException::class);
        MinorUnits::toMinor('10.50', 'JPY');
    }

    public function test_cancel_pending_only(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('createPaymentIntent')->andReturn([
                'id' => 'pi_cancel_1',
                'client_secret' => 'pi_cancel_1_secret',
                'status' => 'requires_payment_method',
            ]);
            $mock->shouldReceive('cancelPaymentIntent')
                ->once()
                ->with('sk_test_pr', 'pi_cancel_1');
        });

        $create = $this->postJson("/api/contracts/{$this->contract->id}/payment-requests");
        $id = (int) $create->json('data.id');
        $token = (string) $create->json('data.token');

        $this->postJson("/api/pay/{$token}/intent")->assertOk();

        $this->postJson("/api/payment-requests/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson("/api/payment-requests/{$id}/cancel")
            ->assertStatus(422);

        $list = $this->getJson("/api/contracts/{$this->contract->id}/payment-requests");
        $list->assertOk()->assertJsonPath('data.0.status', 'cancelled');
    }
}
