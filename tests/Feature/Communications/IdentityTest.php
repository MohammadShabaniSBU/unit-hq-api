<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContractStatus;
use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Employee;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    public function test_per_site_resolution_and_null_disable(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $siteA = Site::factory()->create(['name' => 'Site A']);
        $siteB = Site::factory()->create(['name' => 'Site B']);

        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'test-key'],
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $siteA->id,
            'channel' => Channel::Email,
            'account_id' => $account->id,
            'from_name' => 'Desk A',
            'from_email' => 'desk-a@example.com',
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $siteB->id,
            'channel' => Channel::Email,
            'account_id' => $account->id,
            'from_name' => 'Desk B',
            'from_email' => 'desk-b@example.com',
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Pat',
            'email' => 'pat@example.com',
        ]);
        $this->givePrimaryEmail($contact, 'pat@example.com');

        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $siteB->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $siteB->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);

        $thread = $this->makeInboxThread($contact, [
            'subject' => 'About unit B',
            'channel' => Channel::Email,
        ]);

        $context = $this->getJson('/api/inbox/threads/'.$thread->id.'/compose-context');
        $context->assertOk();
        $context->assertJsonPath('data.from_identity.address', 'desk-b@example.com');
        $context->assertJsonPath('data.from_identity.label', 'Desk B');

        $captured = null;
        Http::fake([
            'api.brevo.com/v3/smtp/email' => function ($request) use (&$captured) {
                $captured = $request->data();

                return Http::response(['messageId' => 'brevo-id-b'], 201);
            },
        ]);

        $reply = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'Reply from site B identity.',
        ]);
        $reply->assertCreated();
        $this->assertIsArray($captured);
        $this->assertSame('desk-b@example.com', $captured['sender']['email'] ?? null);

        // Null identity disables compose (site resolves, but no sender identity row).
        SiteSenderIdentity::query()->delete();

        $lonely = Contact::factory()->create(['email' => 'lonely@example.com']);
        $this->givePrimaryEmail($lonely, 'lonely@example.com');
        $lonelyThread = $this->makeInboxThread($lonely, [
            'subject' => 'Hello',
            'channel' => Channel::Email,
        ]);

        $nullContext = $this->getJson('/api/inbox/threads/'.$lonelyThread->id.'/compose-context');
        $nullContext->assertOk();
        $nullContext->assertJsonPath('data.from_identity', null);

        $blocked = $this->postJson('/api/inbox/threads/'.$lonelyThread->id.'/reply', [
            'body_text' => 'Should fail.',
        ]);
        $blocked->assertStatus(422);
        $blocked->assertJsonPath('message', 'Configure a sender identity before replying.');
    }
}
