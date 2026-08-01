<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\PaymentInstrumentType;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderAccount;
use App\Models\Site;
use App\Models\StripeWebhookEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Payments\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Contact $contact;

    private LegalEntity $entity;

    private PaymentProviderAccount $account;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);

        $this->contact = Contact::factory()->create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.com',
        ]);
        $this->entity = LegalEntity::factory()->create(['legal_name' => 'Payco SL']);
        $this->account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $this->entity->id,
            'secret_key' => 'sk_test_pm',
            'publishable_key' => 'pk_test_pm',
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
        ]);
        $this->contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $this->contract->start_date,
            'effective_to' => null,
        ]);
    }

    public function test_created_only_via_webhook(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('createCustomer')->andReturn(['id' => 'cus_setup_1']);
            $mock->shouldReceive('createSetupIntent')->andReturn([
                'id' => 'seti_1',
                'client_secret' => 'seti_1_secret_xxx',
            ]);
            $mock->shouldReceive('retrievePaymentMethod')
                ->with('sk_test_pm', 'pm_webhook_1')
                ->andReturn([
                    'id' => 'pm_webhook_1',
                    'type' => 'card',
                    'card' => [
                        'brand' => 'visa',
                        'last4' => '4242',
                        'exp_month' => 12,
                        'exp_year' => 2030,
                    ],
                ]);
        });

        // Setup endpoint issues a SetupIntent — does not create a local row.
        $setup = $this->postJson("/api/contacts/{$this->contact->id}/payment-methods/setup", [
            'contract_id' => $this->contract->id,
        ]);
        $setup->assertOk()
            ->assertJsonPath('data.client_secret', 'seti_1_secret_xxx')
            ->assertJsonPath('data.publishable_key', 'pk_test_pm');

        $this->assertSame(0, PaymentMethod::query()->count());

        // Fake client "callback" path — no such write endpoint; a bogus POST
        // must not create instruments.
        // No store endpoint — client callbacks must not create instruments.
        $callback = $this->postJson("/api/contacts/{$this->contact->id}/payment-methods", [
            'stripe_pm_id' => 'pm_callback_should_fail',
            'display_label' => 'Visa ···0000',
        ]);
        $this->assertContains($callback->status(), [404, 405]);

        $this->assertSame(0, PaymentMethod::query()->count());

        $event = StripeWebhookEvent::query()->create([
            'payment_provider_account_id' => $this->account->id,
            'stripe_event_id' => 'evt_setup_1',
            'event_type' => 'setup_intent.succeeded',
            'payload' => $this->setupIntentPayload('pm_webhook_1'),
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        (new ProcessStripeWebhookEvent($event->id))->handle();

        $method = PaymentMethod::query()->first();
        $this->assertNotNull($method);
        $this->assertSame('pm_webhook_1', $method->stripe_pm_id);
        $this->assertSame('Visa ···4242', $method->display_label);
        $this->assertTrue($method->is_default);
        $this->assertSame(PaymentInstrumentType::StripeCard, $method->type);
        $this->assertSame('processed', $event->fresh()->processing_status);
    }

    public function test_default_per_account_semantics(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('retrievePaymentMethod')
                ->andReturnUsing(function (string $secret, string $pmId): array {
                    return [
                        'id' => $pmId,
                        'type' => 'card',
                        'card' => [
                            'brand' => 'mastercard',
                            'last4' => substr($pmId, -4),
                            'exp_month' => 1,
                            'exp_year' => 2031,
                        ],
                    ];
                });
        });

        $first = $this->processSetupEvent('evt_def_1', 'pm_def_aaaa');
        $this->assertTrue($first->is_default);

        $second = $this->processSetupEvent('evt_def_2', 'pm_def_bbbb');
        $this->assertFalse($second->is_default);
        $this->assertTrue($first->fresh()->is_default);

        $flip = $this->patchJson("/api/payment-methods/{$second->id}", [
            'is_default' => true,
        ]);
        $flip->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        // Partial unique: cannot have two defaults for the same contact+account.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        PaymentMethod::query()->create([
            'contact_id' => $this->contact->id,
            'type' => PaymentInstrumentType::StripeCard,
            'stripe_pm_id' => 'pm_def_clash',
            'payment_provider_account_id' => $this->account->id,
            'display_label' => 'Visa ···9999',
            'is_default' => true,
        ]);
    }

    public function test_detach_archive_and_autopay_guard(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('retrievePaymentMethod')->andReturn([
                'id' => 'pm_detach_1',
                'type' => 'card',
                'card' => [
                    'brand' => 'visa',
                    'last4' => '1111',
                    'exp_month' => 6,
                    'exp_year' => 2029,
                ],
            ]);
            $mock->shouldReceive('detachPaymentMethod')
                ->once()
                ->with('sk_test_pm', 'pm_detach_1');
        });

        $method = $this->processSetupEvent('evt_detach_1', 'pm_detach_1');

        $this->contract->update(['payment_method_id' => $method->id]);

        $refused = $this->deleteJson("/api/payment-methods/{$method->id}");
        $refused->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);

        $this->assertNull($method->fresh()->archived_at);

        $this->contract->update(['payment_method_id' => null]);

        $this->deleteJson("/api/payment-methods/{$method->id}")
            ->assertNoContent();

        $archived = $method->fresh();
        $this->assertNotNull($archived->archived_at);
        $this->assertFalse($archived->is_default);

        $this->getJson("/api/contacts/{$this->contact->id}/payment-methods")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function processSetupEvent(string $eventId, string $pmId): PaymentMethod
    {
        $event = StripeWebhookEvent::query()->create([
            'payment_provider_account_id' => $this->account->id,
            'stripe_event_id' => $eventId,
            'event_type' => 'setup_intent.succeeded',
            'payload' => $this->setupIntentPayload($pmId),
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        (new ProcessStripeWebhookEvent($event->id))->handle();

        return PaymentMethod::query()->where('stripe_pm_id', $pmId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function setupIntentPayload(string $pmId): array
    {
        return [
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => 'setup_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'seti_'.uniqid(),
                    'object' => 'setup_intent',
                    'payment_method' => $pmId,
                    'metadata' => [
                        'contact_id' => (string) $this->contact->id,
                        'payment_provider_account_id' => (string) $this->account->id,
                    ],
                ],
            ],
        ];
    }
}
