<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\LogChannel;
use App\Models\AgentChannelBinding;
use App\Models\AiAgent;
use App\Models\Site;
use App\Support\Ai\Agents\ConciergeAgentDefinition;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Ai\VoiceToolSurface;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class AgentChannelBindingApiTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function lists_live_bindings(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales']);
        $live = AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Sms,
        ]);
        AgentChannelBinding::factory()->archived()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Email,
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentBindingManage));

        $this->getJson('/api/ai/agents/bindings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $live->id)
            ->assertJsonPath('data.0.channel', AgentChannel::Sms->value)
            ->assertJsonPath('data.0.agent.key', 'sales')
            ->assertJsonPath('data.0.allowed_tools', (new ConciergeAgentDefinition)->toolKeys(null))
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function voice_binding_projects_the_voice_tool_surface(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'concierge', 'name' => 'Customer Agent']);
        AgentChannelBinding::factory()->auto()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Voice,
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentBindingManage));

        $this->getJson('/api/ai/agents/bindings')
            ->assertOk()
            ->assertJsonPath('data.0.channel', AgentChannel::Voice->value)
            ->assertJsonPath('data.0.allowed_tools', VoiceToolSurface::keys());
    }

    #[Test]
    public function stores_a_binding_and_writes_activity(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales']);
        $employee = $this->employeeWithPermission(Permission::AiAgentBindingManage);
        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/ai/agents/bindings', $this->payload($agent))
            ->assertCreated()
            ->assertJsonPath('data.channel', AgentChannel::Sms->value)
            ->assertJsonPath('data.mode', BindingMode::Draft->value)
            ->assertJsonPath('data.site_id', null);

        $id = $response->json('data.id');
        $this->assertDatabaseHas('agent_channel_bindings', [
            'id' => $id,
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Sms->value,
            'mode' => BindingMode::Draft->value,
            'updated_by_employee_id' => $employee->id,
        ]);

        $this->assertActivity('ai.binding.created', $id, $employee->id);
    }

    #[Test]
    public function updates_a_binding_and_writes_activity(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'name' => 'Support']);
        $binding = AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Email,
            'mode' => BindingMode::Draft,
        ]);
        $employee = $this->employeeWithPermission(Permission::AiAgentBindingManage);
        Sanctum::actingAs($employee);

        $this->putJson('/api/ai/agents/bindings/'.$binding->id, $this->payload($agent, [
            'channel' => AgentChannel::Email->value,
            'mode' => BindingMode::Auto->value,
            'audience' => BindingAudience::ExistingTenants->value,
            'outside_hours' => OutsideHoursPolicy::Answer->value,
        ]))
            ->assertOk()
            ->assertJsonPath('data.mode', BindingMode::Auto->value)
            ->assertJsonPath('data.audience', BindingAudience::ExistingTenants->value);

        $this->assertActivity('ai.binding.updated', $binding->id, $employee->id);
    }

    #[Test]
    public function archives_a_binding_and_writes_activity(): void
    {
        $agent = AiAgent::factory()->create();
        $binding = AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Whatsapp,
        ]);
        $employee = $this->employeeWithPermission(Permission::AiAgentBindingManage);
        Sanctum::actingAs($employee);

        $this->deleteJson('/api/ai/agents/bindings/'.$binding->id)
            ->assertOk()
            ->assertJsonPath('data.id', $binding->id);

        $this->assertNotNull($binding->fresh()?->archived_at);
        $this->assertActivity('ai.binding.archived', $binding->id, $employee->id);
    }

    #[Test]
    public function rejects_a_second_live_binding_for_the_same_channel_and_site(): void
    {
        $agent = AiAgent::factory()->create();
        $other = AiAgent::factory()->create();
        $site = Site::factory()->create();

        AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Sms,
            'site_id' => $site->id,
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentBindingManage));

        $this->postJson('/api/ai/agents/bindings', $this->payload($other, [
            'site_id' => $site->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel']);
    }

    #[Test]
    public function stores_a_voice_binding(): void
    {
        $agent = AiAgent::factory()->create();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentBindingManage));

        $this->postJson('/api/ai/agents/bindings', $this->payload($agent, [
            'channel' => AgentChannel::Voice->value,
            'mode' => BindingMode::Auto->value,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.channel', AgentChannel::Voice->value);
    }

    #[Test]
    public function rejects_draft_mode_on_a_voice_binding(): void
    {
        $agent = AiAgent::factory()->create();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentBindingManage));

        $this->postJson('/api/ai/agents/bindings', $this->payload($agent, [
            'channel' => AgentChannel::Voice->value,
            'mode' => BindingMode::Draft->value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);

        $binding = AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Voice,
            'mode' => BindingMode::Auto,
        ]);

        $this->putJson('/api/ai/agents/bindings/'.$binding->id, $this->payload($agent, [
            'channel' => AgentChannel::Voice->value,
            'mode' => BindingMode::Draft->value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);
    }

    #[Test]
    public function rejects_internal_with_the_unbound_message(): void
    {
        $agent = AiAgent::factory()->create();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentBindingManage));

        $this->postJson('/api/ai/agents/bindings', $this->payload($agent, [
            'channel' => AgentChannel::Internal->value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel'])
            ->assertJsonPath('errors.channel.0', 'This channel cannot be bound to an agent.');
    }

    #[Test]
    public function forbids_employees_without_the_permission(): void
    {
        $agent = AiAgent::factory()->create();
        Sanctum::actingAs($this->employeeWithoutPermissions());

        $this->getJson('/api/ai/agents/bindings')->assertForbidden();
        $this->postJson('/api/ai/agents/bindings', $this->payload($agent))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(AiAgent $agent, array $overrides = []): array
    {
        return array_merge([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Sms->value,
            'site_id' => null,
            'mode' => BindingMode::Draft->value,
            'audience' => BindingAudience::KnownContacts->value,
            'outside_hours' => OutsideHoursPolicy::Inbox->value,
        ], $overrides);
    }

    private function assertActivity(string $description, int $subjectId, int $causerId): void
    {
        $rows = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', $description)
            ->where('subject_id', $subjectId)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($causerId, $rows->first()?->causer_id);
        $this->assertNotNull($rows->first()?->properties->get('channel'));
    }
}
