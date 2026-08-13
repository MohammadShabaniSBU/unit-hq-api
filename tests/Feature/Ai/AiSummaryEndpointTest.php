<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Jobs\GenerateAiSummary;
use App\Models\AiSummary;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Interaction;
use App\Support\Ai\SummaryStatus;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

class AiSummaryEndpointTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function post_queues_generation_with_202(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->owner);

        $contact = Contact::factory()->create();
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Hello',
        ]);

        $this->postJson("/api/contacts/{$contact->id}/ai-summary", ['locale' => 'en'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', SummaryStatus::Queued->value);

        Queue::assertPushed(GenerateAiSummary::class);
    }

    #[Test]
    public function post_returns_409_when_in_flight(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->owner);

        $contact = Contact::factory()->create();
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Hello',
        ]);

        AiSummary::factory()->forContact($contact)->create([
            'status' => SummaryStatus::Queued,
            'requested_by_employee_id' => $this->owner->id,
        ]);

        $this->postJson("/api/contacts/{$contact->id}/ai-summary")
            ->assertStatus(409)
            ->assertJsonPath('message', 'errors.ai_summary.in_flight');
    }

    #[Test]
    public function post_returns_429_when_too_soon(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->owner);
        config(['ai.summaries.min_regenerate_seconds' => 30]);

        $contact = Contact::factory()->create();
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Hello',
        ]);

        AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $this->owner->id,
            'generated_at' => now(),
        ]);

        $this->postJson("/api/contacts/{$contact->id}/ai-summary")
            ->assertStatus(429)
            ->assertJsonPath('message', 'errors.ai_summary.too_soon');
    }

    #[Test]
    public function post_returns_422_when_context_empty(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->owner);

        $contact = Contact::factory()->create();

        $this->postJson("/api/contacts/{$contact->id}/ai-summary")
            ->assertStatus(422)
            ->assertJsonPath('message', 'errors.ai_summary.context_empty');
    }

    #[Test]
    public function post_without_generate_permission_returns_403_with_permission(): void
    {
        Queue::fake();
        RbacSystemRoleSeeder::upsertSystemRoles();

        $viewer = Employee::factory()->withoutRoleGrant()->create();
        $this->grantRole($viewer, 'read_only', null);

        Sanctum::actingAs($viewer);

        $contact = Contact::factory()->create();
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Hello',
        ]);

        $this->postJson("/api/contacts/{$contact->id}/ai-summary")
            ->assertForbidden()
            ->assertJsonPath('message', 'errors.forbidden')
            ->assertJsonPath('data.permission', Permission::AiSummaryGenerate->value);
    }

    #[Test]
    public function site_scoped_employee_gets_404_for_contact_outside_list_grants(): void
    {
        // Grant site_manager on site A so the agent has ai_summary.* but not site B.
        $this->grantRole($this->agent, 'site_manager', $this->siteA);
        $this->agent->forgetPermissionMap();

        Sanctum::actingAs($this->agent);

        $contact = Contact::factory()->create();
        Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $this->siteB->id,
        ]);

        $this->getJson("/api/contacts/{$contact->id}/ai-summary")->assertNotFound();
        $this->postJson("/api/contacts/{$contact->id}/ai-summary")->assertNotFound();
    }

    #[Test]
    public function get_returns_current_in_flight_and_flags(): void
    {
        Sanctum::actingAs($this->owner);

        $contact = Contact::factory()->create();
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Hello',
        ]);

        AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $this->owner->id,
            'generated_at' => now()->subHour(),
            'source_digest' => 'stale-digest',
        ]);

        $this->getJson("/api/contacts/{$contact->id}/ai-summary")
            ->assertOk()
            ->assertJsonPath('data.current.status', SummaryStatus::Succeeded->value)
            ->assertJsonPath('data.in_flight', null)
            ->assertJsonPath('data.is_stale', true)
            ->assertJsonPath('data.can_generate', true);
    }

    #[Test]
    public function deal_endpoints_mirror_contact_shape(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->owner);

        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $this->siteA->id,
        ]);
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Deal note',
        ]);

        $this->postJson("/api/deals/{$deal->id}/ai-summary", ['locale' => 'es'])
            ->assertStatus(202)
            ->assertJsonPath('data.locale', 'es');

        $this->getJson("/api/deals/{$deal->id}/ai-summary")->assertOk();
        $this->getJson("/api/deals/{$deal->id}/ai-summary/history")->assertOk();
    }
}
