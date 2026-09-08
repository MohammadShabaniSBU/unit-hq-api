<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

/**
 * D-RBAC-1: Contact list scoping + the preserved cross-site detail exception.
 */
class ContactVisibilityTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;
    use SeedsInboxThreads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
    }

    #[Test]
    public function lists_contacts_related_to_granted_sites(): void
    {
        $contactA = Contact::factory()->create(['first_name' => 'Alpha']);
        Deal::factory()->create(['contact_id' => $contactA->id, 'site_id' => $this->siteA->id]);

        $contactB = Contact::factory()->create(['first_name' => 'Beta']);
        Deal::factory()->create(['contact_id' => $contactB->id, 'site_id' => $this->siteB->id]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson('/api/contacts?per_page=100')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($contactA->id, $ids);
        $this->assertNotContains($contactB->id, $ids);
    }

    #[Test]
    public function lists_unassigned_contacts(): void
    {
        // No deal, reservation, contract or thread at all — an unpicked lead.
        $lead = Contact::factory()->create(['first_name' => 'Unassigned']);

        $contactB = Contact::factory()->create(['first_name' => 'Beta']);
        Deal::factory()->create(['contact_id' => $contactB->id, 'site_id' => $this->siteB->id]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson('/api/contacts?per_page=100')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($lead->id, $ids);
        $this->assertNotContains($contactB->id, $ids);
    }

    #[Test]
    public function detail_still_shows_all_site_activity(): void
    {
        // Visible via a site A deal, but also has activity at site B — the
        // detail view must show both, not just the granted-site slice.
        $contact = Contact::factory()->create(['first_name' => 'MultiSite']);
        Deal::factory()->create(['contact_id' => $contact->id, 'site_id' => $this->siteA->id]);
        Deal::factory()->create(['contact_id' => $contact->id, 'site_id' => $this->siteB->id]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();
        $dealSiteIds = collect($response->json('data.deals'))->pluck('site_id')->all();
        $this->assertContains($this->siteA->id, $dealSiteIds);
        $this->assertContains($this->siteB->id, $dealSiteIds);

        // Documented exception (07-people-and-auth.md / D-RBAC-1): a contact
        // related only to an out-of-scope site is invisible in the list, but
        // Contact detail is never route-bound with visibleTo — any
        // ContactView holder can still open it directly.
        $siteBOnly = Contact::factory()->create(['first_name' => 'SiteBOnly']);
        Deal::factory()->create(['contact_id' => $siteBOnly->id, 'site_id' => $this->siteB->id]);

        $listResponse = $this->getJson('/api/contacts?per_page=100')->assertOk();
        $this->assertNotContains(
            $siteBOnly->id,
            collect($listResponse->json('data'))->pluck('id')->all(),
        );

        $this->getJson("/api/contacts/{$siteBOnly->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $siteBOnly->id);
    }

    #[Test]
    public function hides_contact_at_other_site_when_thread_uses_shared_company_account(): void
    {
        $account = $this->seedSharedCompanyEmailAccount();

        $contactB = Contact::factory()->create(['first_name' => 'OtherSite']);
        Deal::factory()->create(['contact_id' => $contactB->id, 'site_id' => $this->siteB->id]);
        $this->makeInboxThread($contactB, [
            'subject' => 'Offer at site B',
        ], [
            'communication_account_id' => $account->id,
        ]);

        Sanctum::actingAs($this->agent);

        $ids = collect($this->getJson('/api/contacts?per_page=100')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertNotContains($contactB->id, $ids);
    }

    #[Test]
    public function shows_contact_when_shared_account_thread_site_matches_grant(): void
    {
        $account = $this->seedSharedCompanyEmailAccount();

        $contactA = Contact::factory()->create(['first_name' => 'GrantedSite']);
        Deal::factory()->create(['contact_id' => $contactA->id, 'site_id' => $this->siteA->id]);
        $this->makeInboxThread($contactA, [
            'subject' => 'Offer at site A',
        ], [
            'communication_account_id' => $account->id,
        ]);

        Sanctum::actingAs($this->agent);

        $ids = collect($this->getJson('/api/contacts?per_page=100')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($contactA->id, $ids);
    }

    private function seedSharedCompanyEmailAccount(): CommunicationAccount
    {
        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'test-key'],
            'status' => CredentialStatus::Connected,
        ]);

        foreach ([$this->siteA, $this->siteB] as $site) {
            SiteSenderIdentity::query()->create([
                'site_id' => $site->id,
                'channel' => Channel::Email,
                'account_id' => $account->id,
                'from_name' => 'Keevaris',
                'from_email' => 'desk-'.$site->id.'@example.com',
                'reply_to_email' => 'reply-'.$site->id.'@example.com',
            ]);
        }

        return $account;
    }
}
