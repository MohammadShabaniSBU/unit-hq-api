<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ChargeType;
use App\Enums\PaymentRequestStatus;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\PaymentProviderAccount;
use App\Models\PaymentRequest;
use App\Models\Site;
use App\Models\StripeWebhookEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Payments\MinorUnits;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

/**
 * S11-03 flagship quick action: a payment link created off the context panel,
 * dropped into a reply, and (test-mode) paid via the standing webhook pipeline.
 */
class QuickActionTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_payment_link_roundtrip(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ren',
            'last_name' => 'Tenant',
            'email' => 'ren.tenant@example.com',
        ]);

        $entity = LegalEntity::factory()->create();
        $account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $entity->id,
        ]);

        $site = Site::factory()->create(['legal_entity_id' => $entity->id]);
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

        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
        ]);
        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $contract->start_date,
            'effective_to' => null,
        ]);

        $charge = Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);

        // 1. Quick action: create the payment request from the context panel.
        $created = $this->postJson("/api/contracts/{$contract->id}/payment-requests")
            ->assertCreated();

        $paymentRequestId = $created->json('data.id');
        $url = $created->json('data.url');
        $this->assertNotNull($url);

        // 2. Snippet insertion: the link lands in the reply body, unmodified.
        $this->seedEmailAccount($site);
        $this->fakeCommunicationProviders();

        $thread = $this->makeInboxThread($contact, ['channel' => \App\Support\Communications\Channel::Email]);
        $bodyText = "Hi Ren, here is your payment link: {$url}";

        $reply = $this->postJson("/api/inbox/threads/{$thread->id}/reply", [
            'body_text' => $bodyText,
        ])->assertCreated();

        $this->assertStringContainsString($url, (string) $reply->json('data.message.body.content'));

        // 3. The sent message really contains the working link, round-tripped
        // through the thread detail the panel/conversation pane reads.
        $detail = $this->getJson("/api/inbox/threads/{$thread->id}")->assertOk();
        $lastMessage = collect($detail->json('data.messages'))->first();
        $this->assertStringContainsString($url, (string) $lastMessage['body']['content']);

        // 4. Test-mode payment marks the request paid via the standing webhook pipeline.
        $piId = 'pi_quick_action_1';
        $event = StripeWebhookEvent::query()->create([
            'payment_provider_account_id' => $account->id,
            'stripe_event_id' => 'evt_quick_action_1',
            'event_type' => 'payment_intent.succeeded',
            'payload' => [
                'id' => 'evt_quick_action_1',
                'object' => 'event',
                'type' => 'payment_intent.succeeded',
                'data' => ['object' => [
                    'id' => $piId,
                    'object' => 'payment_intent',
                    'amount' => MinorUnits::toMinor('100.00', 'EUR'),
                    'currency' => 'eur',
                    'status' => 'succeeded',
                    'metadata' => ['payment_request_id' => (string) $paymentRequestId],
                ]],
            ],
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        (new ProcessStripeWebhookEvent($event->id))->handle();

        $this->assertSame(
            PaymentRequestStatus::Paid,
            PaymentRequest::query()->findOrFail($paymentRequestId)->status,
        );

        // Balance reflects the payment — the same source the context panel reads.
        $this->assertSame('0.00', $contract->fresh()->balanceOwed());
        $this->assertSame('0.00', $charge->fresh()->openAmount());
    }
}
