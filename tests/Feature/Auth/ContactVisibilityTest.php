<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

/**
 * D-RBAC-1: Contact list scoping + the preserved cross-site detail exception.
 */
class ContactVisibilityTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

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
}
