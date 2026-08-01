<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AutopayAttemptStatus;
use App\Enums\BillingRunTrigger;
use App\Enums\ContractStatus;
use App\Enums\MoveOutSettlement;
use App\Enums\PaymentInstrumentType;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\AutopayAttempt;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderAccount;
use App\Models\Setting;
use App\Models\Site;
use App\Models\StripeCustomer;
use App\Models\StripeWebhookEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Billing\BillingRunEngine;
use App\Support\Payments\MinorUnits;
use App\Support\Payments\StripeClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

/**
 * Sprint 06 exit: sign → bill itself → collect itself → invoice paid.
 */
class DemoSpineTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_sign_bill_collect_green(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        $this->actingAs($employee);

        $contact = Contact::factory()->create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'tax_id' => 'B12345678',
        ]);
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create(['legal_name' => 'Demo SL']);
        $account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $entity->id,
            'secret_key' => 'sk_test_demo',
            'publishable_key' => 'pk_test_demo',
        ]);
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => $entity->id,
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            [
                'amount' => '120.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: '0.00',
            moveOutSettlement: MoveOutSettlement::None->value,
            turnoverHoldDays: 0,
            billingHorizonDays: 0,
        ));

        // 1) Sign
        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-15',
            'move_in_date' => '2026-07-15',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '120.00',
            ]],
        ])->assertCreated();

        $contract = Contract::query()->findOrFail($response->json('data.id'));
        $this->assertSame(ContractStatus::Active, $contract->status);

        $method = PaymentMethod::factory()->default()->create([
            'contact_id' => $contact->id,
            'payment_provider_account_id' => $account->id,
            'type' => PaymentInstrumentType::StripeCard,
            'stripe_pm_id' => 'pm_demo_1',
        ]);
        StripeCustomer::query()->create([
            'contact_id' => $contact->id,
            'payment_provider_account_id' => $account->id,
            'stripe_customer_id' => 'cus_demo_1',
        ]);

        $this->putJson("/api/contracts/{$contract->id}/autopay", [
            'enabled' => true,
            'payment_method_id' => $method->id,
        ])->assertOk();

        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('createPaymentIntent')
                ->once()
                ->andReturn([
                    'id' => 'pi_demo_1',
                    'client_secret' => null,
                    'status' => 'succeeded',
                    'last_payment_error' => null,
                ]);
        });

        // 2) Bill itself
        $this->artisan('billing:run', [
            '--trigger' => 'manual',
            '--contract' => $contract->id,
        ])->assertSuccessful();

        $attempt = AutopayAttempt::query()
            ->where('contract_id', $contract->id)
            ->firstOrFail();
        $this->assertSame(AutopayAttemptStatus::Pending, $attempt->status);
        $this->assertSame('pi_demo_1', $attempt->stripe_payment_intent_id);

        // 3) Collect itself (webhook settles)
        $event = StripeWebhookEvent::query()->create([
            'payment_provider_account_id' => $account->id,
            'stripe_event_id' => 'evt_demo_spine_1',
            'event_type' => 'payment_intent.succeeded',
            'payload' => [
                'id' => 'evt_demo_spine_1',
                'object' => 'event',
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'pi_demo_1',
                        'object' => 'payment_intent',
                        'amount' => MinorUnits::toMinor((string) $attempt->amount, 'EUR'),
                        'currency' => 'eur',
                        'status' => 'succeeded',
                        'metadata' => [
                            'autopay_attempt_id' => (string) $attempt->id,
                        ],
                    ],
                ],
            ],
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);
        (new ProcessStripeWebhookEvent($event->id))->handle();

        $this->assertSame(AutopayAttemptStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame('0.00', $contract->fresh()->balanceOwed());

        $invoice = Invoice::query()
            ->where('contract_id', $contract->id)
            ->latest('id')
            ->firstOrFail();

        $resource = $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('paid', $resource['payment_status']);

        Carbon::setTestNow();
    }
}
