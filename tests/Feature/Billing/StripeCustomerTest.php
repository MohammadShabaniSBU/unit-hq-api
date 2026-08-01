<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\PaymentProviderAccount;
use App\Models\StripeCustomer;
use App\Support\Payments\StripeClient;
use App\Support\Payments\StripeCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class StripeCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_or_create_race_safe(): void
    {
        $this->actingAs(Employee::factory()->manager()->create());

        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);
        $entity = LegalEntity::factory()->create();
        $account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $entity->id,
            'secret_key' => 'sk_test_race',
        ]);

        // Simulate a concurrent winner inserting the pair while we create the
        // remote Stripe customer — recovery must re-read the unique index.
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock) use ($contact, $account): void {
            $mock->shouldReceive('createCustomer')
                ->once()
                ->with('sk_test_race', Mockery::on(function (array $params): bool {
                    return ($params['name'] ?? null) === 'Ada Lovelace'
                        && ($params['email'] ?? null) === 'ada@example.com';
                }))
                ->andReturnUsing(function () use ($contact, $account): array {
                    DB::table('stripe_customers')->insert([
                        'contact_id' => $contact->id,
                        'payment_provider_account_id' => $account->id,
                        'stripe_customer_id' => 'cus_race_peer',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return ['id' => 'cus_race_winner'];
                });
        });

        $resolved = StripeCustomers::for($contact, $account);

        $this->assertSame('cus_race_peer', $resolved->stripe_customer_id);
        $this->assertSame(1, StripeCustomer::query()->count());
        $this->assertTrue(
            StripeCustomer::query()
                ->where('contact_id', $contact->id)
                ->where('payment_provider_account_id', $account->id)
                ->exists()
        );

        // Idempotent second call — no further Stripe create.
        $again = StripeCustomers::for($contact, $account);
        $this->assertTrue($resolved->is($again));
    }
}
