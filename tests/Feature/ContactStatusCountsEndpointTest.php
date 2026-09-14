<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContactLifecycleStatus;
use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

class ContactStatusCountsEndpointTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
    }

    #[Test]
    public function returns_every_status_including_zeros(): void
    {
        Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        Contact::factory()->create(['status' => ContactLifecycleStatus::Lead]);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/contacts/status-counts')
            ->assertOk()
            ->assertJsonPath('data.prospect', 2)
            ->assertJsonPath('data.lead', 1)
            ->assertJsonPath('data.opportunity', 0)
            ->assertJsonPath('data.tenant', 0)
            ->assertJsonPath('data.past_tenant', 0)
            ->assertJsonPath('data.lost', 0);
    }

    #[Test]
    public function totals_match_list_meta_and_honor_site_id(): void
    {
        $prospectA = Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        Deal::factory()->create(['contact_id' => $prospectA->id, 'site_id' => $this->siteA->id]);

        $leadA = Contact::factory()->create(['status' => ContactLifecycleStatus::Lead]);
        Deal::factory()->create(['contact_id' => $leadA->id, 'site_id' => $this->siteA->id]);

        $prospectB = Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        Deal::factory()->create(['contact_id' => $prospectB->id, 'site_id' => $this->siteB->id]);

        Contact::factory()->create(['status' => ContactLifecycleStatus::Lost]);

        Sanctum::actingAs($this->owner);

        $list = $this->getJson('/api/contacts?per_page=100&site_id='.$this->siteA->id)->assertOk();
        $counts = $this->getJson('/api/contacts/status-counts?site_id='.$this->siteA->id)->assertOk();

        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame(1, $counts->json('data.prospect'));
        $this->assertSame(1, $counts->json('data.lead'));
        $this->assertSame(0, $counts->json('data.opportunity'));
        $this->assertSame(0, $counts->json('data.tenant'));
        $this->assertSame(0, $counts->json('data.past_tenant'));
        $this->assertSame(0, $counts->json('data.lost'));
        $this->assertSame(
            (int) $list->json('meta.total'),
            collect($counts->json('data'))->sum(),
        );
    }
}
