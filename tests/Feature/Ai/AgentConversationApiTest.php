<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\AiUsageEvent;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\RecordingTool;
use Tests\Support\Ai\TestAgentDefinition;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

class AgentConversationApiTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    private FakeModelDriver $driver;

    private RecordingTool $recording;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
        RbacSystemRoleSeeder::upsertSystemRoles();

        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);

        $this->recording = new RecordingTool;
        app(ToolRegistry::class)->register($this->recording);
        app(AgentRegistry::class)->register(new TestAgentDefinition);

        config(['agents.demo_enabled' => true]);
    }

    #[Test]
    public function lists_active_agents(): void
    {
        AiAgent::factory()->create(['key' => 'support', 'name' => 'Support Agent', 'is_active' => true]);
        AiAgent::factory()->archived()->create(['key' => 'retired']);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/ai/agents')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'support')
            ->assertJsonPath('meta.demo_enabled', true)
            ->assertJsonMissing(['key' => 'retired']);
    }

    #[Test]
    public function agents_list_meta_demo_enabled_follows_config(): void
    {
        config(['agents.demo_enabled' => false]);
        AiAgent::factory()->create(['key' => 'support', 'name' => 'Support Agent', 'is_active' => true]);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/ai/agents')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'support')
            ->assertJsonPath('meta.demo_enabled', false);
    }

    #[Test]
    public function demo_personas_are_unreachable_when_flag_is_off(): void
    {
        config(['agents.demo_enabled' => false]);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/ai/demo-personas')
            ->assertNotFound()
            ->assertJsonPath('message', 'errors.agent.demo_disabled');
    }

    #[Test]
    public function origin_demo_is_refused_when_flag_is_off(): void
    {
        config(['agents.demo_enabled' => false]);
        $agent = AiAgent::factory()->create(['key' => 'support', 'is_active' => true]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Webchat->value,
            'origin' => AgentOrigin::Demo->value,
            'verification_level' => VerificationLevel::Anonymous->value,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('origin');
    }

    #[Test]
    public function channel_voice_is_rejected(): void
    {
        config(['agents.demo_enabled' => true]);
        $agent = AiAgent::factory()->create(['key' => 'support', 'is_active' => true]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Voice->value,
            'origin' => AgentOrigin::Demo->value,
            'verification_level' => VerificationLevel::Anonymous->value,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');
    }

    #[Test]
    public function verification_level_is_prohibited_for_non_demo_origins(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'is_active' => true]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Email->value,
            'origin' => AgentOrigin::Inbox->value,
            'verification_level' => VerificationLevel::Verified->value,
            'contact_id' => Contact::factory()->create()->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('verification_level');
    }

    #[Test]
    public function audience_cannot_be_set_by_the_client(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'is_active' => true]);
        $contact = Contact::factory()->create();
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Webchat->value,
            'origin' => AgentOrigin::Demo->value,
            'contact_id' => $contact->id,
            'verification_level' => VerificationLevel::Verified->value,
            'audience' => AgentAudience::Internal->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.audience', AgentAudience::Customer->value)
            ->assertJsonPath('data.contact_id', $contact->id);

        $this->assertDatabaseHas('agent_conversations', [
            'contact_id' => $contact->id,
            'audience' => AgentAudience::Customer->value,
            'employee_id' => null,
        ]);
    }

    #[Test]
    public function demo_without_contact_is_a_customer_anonymous_principal(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'is_active' => true]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Sms->value,
            'origin' => AgentOrigin::Demo->value,
            'verification_level' => VerificationLevel::Anonymous->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.audience', AgentAudience::Customer->value)
            ->assertJsonPath('data.verification_level', VerificationLevel::Anonymous->value)
            ->assertJsonPath('data.contact_id', null);
    }

    #[Test]
    public function verified_without_contact_is_rejected(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'is_active' => true]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Webchat->value,
            'origin' => AgentOrigin::Demo->value,
            'verification_level' => VerificationLevel::Verified->value,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('verification_level');
    }

    #[Test]
    public function starting_a_conversation_writes_activity_without_a_prompt(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'is_active' => true]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Webchat->value,
            'origin' => AgentOrigin::Demo->value,
            'verification_level' => VerificationLevel::Anonymous->value,
        ])->assertCreated()->json('data.id');

        $row = Activity::query()
            ->where('log_name', LogChannel::Ai->value)
            ->where('description', 'agent.conversation.started')
            ->where('subject_id', $id)
            ->first();

        $this->assertNotNull($row);
        $properties = $row->properties?->toArray() ?? [];
        $this->assertSame('support', $properties['agent_key'] ?? null);
        $this->assertArrayNotHasKey('input', $properties);
        $this->assertArrayNotHasKey('content', $properties);
        $this->assertArrayNotHasKey('draft', $properties);
    }

    #[Test]
    public function turns_on_a_handed_off_conversation_return_409(): void
    {
        $conversation = AgentConversation::factory()->create([
            'state' => ConversationState::HandedOff,
            'created_by_employee_id' => $this->owner->id,
        ]);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/agent-conversations/{$conversation->id}/turns", [
            'input' => 'hello',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'errors.agent.conversation_not_active');
    }

    #[Test]
    public function sse_emits_tool_using_turn_events_in_order(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'test', 'is_active' => true]);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'created_by_employee_id' => $this->owner->id,
            'origin' => AgentOrigin::Demo,
            'state' => ConversationState::Active,
        ]);

        $this->driver
            ->enqueueToolCalls([['name' => 'test.record', 'id' => 'c1', 'arguments' => ['label' => 'balance']]])
            ->enqueueText('The figure is €84,70 (incl. 21% IVA).');

        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/agent-conversations/{$conversation->id}/turns", [
            'input' => 'How much is it?',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));

        $body = $response->streamedContent();
        $names = array_column($this->sseEvents($body), 'event');
        $this->assertSame(
            ['turn.started', 'tool.started', 'tool.finished', 'token', 'guardrail', 'turn.completed'],
            $this->eventSubsequence($names, [
                'turn.started',
                'tool.started',
                'tool.finished',
                'token',
                'guardrail',
                'turn.completed',
            ]),
        );

        $started = $this->firstSse($body, 'tool.started');
        $this->assertSame('test.record', $started['data']['tool_key'] ?? null);
        $this->assertSame(['label' => 'balance'], $started['data']['arguments'] ?? null);

        $finished = $this->firstSse($body, 'tool.finished');
        $this->assertSame('ok', $finished['data']['status'] ?? null);
        $this->assertArrayHasKey('duration_ms', $finished['data']);
        $this->assertArrayHasKey('result_summary', $finished['data']);

        $usages = AiUsageEvent::query()->where('agent_conversation_id', $conversation->id)->get();
        $this->assertGreaterThanOrEqual(1, $usages->count());
        foreach ($usages as $usage) {
            $this->assertSame($agent->id, $usage->ai_agent_id);
            $this->assertNull($usage->employee_id);
            $this->assertSame('agent', $usage->purpose);
            $this->assertNotNull($usage->settled_at);
        }
    }

    #[Test]
    public function email_turn_exposes_extracted_subject_on_completed_and_message(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'test', 'is_active' => true]);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'created_by_employee_id' => $this->owner->id,
            'origin' => AgentOrigin::Demo,
            'channel' => AgentChannel::Email,
            'state' => ConversationState::Active,
        ]);

        $this->driver->enqueueText("Subject: Availability\nWe have space this week.");
        Sanctum::actingAs($this->owner);

        $body = $this->postJson("/api/agent-conversations/{$conversation->id}/turns", [
            'input' => 'any units free',
        ])->assertOk()->streamedContent();

        $completed = $this->firstSse($body, 'turn.completed');
        $this->assertSame('Availability', $completed['data']['subject'] ?? null);
        $this->assertArrayHasKey('message_id', $completed['data']);

        $message = $conversation->messages()
            ->where('role', 'assistant')
            ->orderByDesc('sequence')
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('Availability', $message->subject);
        $this->assertIsString($message->content);
        $this->assertStringNotContainsString('Subject:', $message->content);
        $this->assertStringContainsString('We have space this week.', $message->content);

        $this->getJson("/api/agent-conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.1.subject', 'Availability')
            ->assertJsonPath('data.messages.1.content', $message->content);
    }

    #[Test]
    public function blocked_draft_is_logged_without_the_draft_text(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'test', 'is_active' => true]);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'created_by_employee_id' => $this->owner->id,
            'origin' => AgentOrigin::Demo,
        ]);
        $this->driver->enqueueText('Invented balance €12.00');
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/agent-conversations/{$conversation->id}/turns", [
            'input' => 'what do I owe?',
        ])->assertOk()->streamedContent();

        $row = Activity::query()
            ->where('log_name', LogChannel::Ai->value)
            ->where('description', 'agent.guardrail.blocked')
            ->where('subject_id', $conversation->id)
            ->first();

        $this->assertNotNull($row);
        $properties = $row->properties?->toArray() ?? [];
        $this->assertSame('grounding', $properties['guard'] ?? null);
        $this->assertSame('grounding', $properties['blocked_by'] ?? null);
        $encoded = json_encode($properties);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Invented balance', $encoded);
        $this->assertStringNotContainsString('€12.00', $encoded);
    }

    #[Test]
    public function inbound_handoff_is_logged_without_the_draft(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'is_active' => true]);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'created_by_employee_id' => $this->owner->id,
            'locale' => 'en',
        ]);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/agent-conversations/{$conversation->id}/turns", [
            'input' => 'I got a letter about an auction',
        ])->assertOk()->streamedContent();

        $row = Activity::query()
            ->where('log_name', LogChannel::Ai->value)
            ->where('description', 'agent.handoff')
            ->where('subject_id', $conversation->id)
            ->first();

        $this->assertNotNull($row);
        $properties = $row->properties?->toArray() ?? [];
        $this->assertArrayHasKey('reason', $properties);
        $this->assertArrayHasKey('trigger_source', $properties);
        $encoded = json_encode($properties);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('auction', $encoded);
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $conversation = AgentConversation::factory()->create([
            'created_by_employee_id' => $this->owner->id,
            'state' => ConversationState::Active,
        ]);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/agent-conversations/{$conversation->id}/close")
            ->assertOk()
            ->assertJsonPath('data.state', ConversationState::Closed->value);

        $this->postJson("/api/agent-conversations/{$conversation->id}/close")
            ->assertOk()
            ->assertJsonPath('data.state', ConversationState::Closed->value);
    }

    #[Test]
    public function site_scoped_operator_only_lists_conversations_they_created(): void
    {
        $operator = Employee::factory()->withoutRoleGrant()->create();
        $this->grantRole($operator, 'site_manager', $this->siteA);
        $operator->forgetPermissionMap();

        $mine = AgentConversation::factory()->create([
            'created_by_employee_id' => $operator->id,
        ]);
        AgentConversation::factory()->create([
            'created_by_employee_id' => $this->owner->id,
        ]);

        Sanctum::actingAs($operator);

        $this->getJson('/api/agent-conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $mine->id);

        $this->assertTrue($operator->allowsPermission(Permission::AiAgentUse));
    }

    /**
     * @return list<array{event: string, data: mixed}>
     */
    private function sseEvents(string $body): array
    {
        $events = [];
        foreach (preg_split("/\n\n/", trim($body)) ?: [] as $chunk) {
            if ($chunk === '' || str_starts_with($chunk, ':')) {
                continue;
            }

            $event = null;
            $data = null;
            foreach (explode("\n", $chunk) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                }
                if (str_starts_with($line, 'data: ')) {
                    $data = json_decode(substr($line, 6), true);
                }
            }

            if ($event !== null) {
                $events[] = ['event' => $event, 'data' => $data];
            }
        }

        return $events;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $expected
     * @return list<string>
     */
    private function eventSubsequence(array $names, array $expected): array
    {
        $matched = [];
        $cursor = 0;
        foreach ($names as $name) {
            if ($cursor < count($expected) && $name === $expected[$cursor]) {
                $matched[] = $name;
                $cursor++;
            }
        }

        return $matched;
    }

    /**
     * @return array{event: string, data: mixed}
     */
    private function firstSse(string $body, string $event): array
    {
        foreach ($this->sseEvents($body) as $row) {
            if ($row['event'] === $event) {
                return $row;
            }
        }

        $this->fail("Missing SSE event [{$event}]");
    }
}
