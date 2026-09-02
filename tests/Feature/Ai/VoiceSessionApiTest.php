<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\Contact;
use App\Models\Site;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSession;
use App\Models\VoiceSessionTurn;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class VoiceSessionApiTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function forbids_employees_without_the_permission(): void
    {
        $session = $this->sessionAt(Site::factory()->create());

        Sanctum::actingAs($this->employeeWithoutPermissions());

        $this->getJson('/api/voice-sessions')->assertForbidden();
        $this->getJson('/api/voice-sessions/'.$session->id)->assertForbidden();
    }

    #[Test]
    public function company_wide_list_includes_every_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $a = $this->sessionAt($siteA);
        $b = $this->sessionAt($siteB);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $ids = collect($this->getJson('/api/voice-sessions')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    #[Test]
    public function site_scoped_employee_sees_only_own_site_rows(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $a = $this->sessionAt($siteA);
        $b = $this->sessionAt($siteB);

        Sanctum::actingAs($this->employeeWithSiteScopedPermission(Permission::AiAgentUse, $siteA));

        $ids = collect($this->getJson('/api/voice-sessions')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);

        $this->getJson('/api/voice-sessions/'.$b->id)->assertNotFound();
        $this->getJson('/api/voice-sessions/'.$a->id)->assertOk()->assertJsonPath('data.id', $a->id);
    }

    #[Test]
    public function filters_by_date_and_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $old = $this->sessionAt($siteA, ['started_at' => Carbon::parse('2026-08-01 12:00:00')]);
        $kept = $this->sessionAt($siteA, ['started_at' => Carbon::parse('2026-09-02 12:00:00')]);
        $otherSite = $this->sessionAt($siteB, ['started_at' => Carbon::parse('2026-09-02 12:00:00')]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $ids = collect($this->getJson('/api/voice-sessions?date_from=2026-09-01&date_to=2026-09-02&site_id='.$siteA->id)
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertSame([$kept->id], $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertNotContains($otherSite->id, $ids);
    }

    #[Test]
    public function list_omits_disposition_duration_and_bridge_secrets(): void
    {
        $started = Carbon::parse('2026-09-02 10:00:00');
        $turnAt = Carbon::parse('2026-09-02 10:03:20');
        $session = $this->sessionAt(Site::factory()->create(), ['started_at' => $started]);
        VoiceSessionTurn::factory()->create([
            'voice_session_id' => $session->id,
            'transfer' => true,
            'created_at' => $turnAt,
            'updated_at' => $turnAt,
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $row = $this->getJson('/api/voice-sessions')->assertOk()->json('data.0');

        $this->assertSame($session->id, $row['id']);
        $this->assertSame(200, $row['delegated_span_seconds']);
        $this->assertTrue($row['transfer_requested']);
        $this->assertArrayNotHasKey('disposition', $row);
        $this->assertArrayNotHasKey('duration_seconds', $row);
        $this->assertArrayNotHasKey('bridge_session_id', $row);
        $this->assertArrayNotHasKey('voice_bridge_token_id', $row);
        $this->assertArrayNotHasKey('conversation', $row);
        $this->assertArrayNotHasKey('turns', $row);
    }

    #[Test]
    public function show_includes_messages_trace_and_returned_answer(): void
    {
        $site = Site::factory()->create();
        $contact = Contact::factory()->create();
        $conversation = AgentConversation::factory()->create([
            'channel' => AgentChannel::Voice,
            'origin' => AgentOrigin::Voice,
            'site_id' => $site->id,
            'contact_id' => $contact->id,
            'verification_level' => VerificationLevel::ChannelAsserted,
        ]);
        $session = $this->sessionAt($site, [
            'agent_conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'caller_number' => '+34600111222',
        ]);

        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => 1,
            'role' => AgentMessageRole::User,
            'content' => 'What sizes do you have?',
        ]);
        $returned = AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => 2,
            'role' => AgentMessageRole::Assistant,
            'content' => 'I will text you the sizes we have.',
        ]);
        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => 3,
            'role' => AgentMessageRole::Assistant,
            'content' => 'late draft',
            'blocked_by' => 'turn_timeout',
        ]);

        VoiceSessionTurn::factory()->create([
            'voice_session_id' => $session->id,
            'answer_text' => 'I will text you the sizes we have.',
            'transfer' => false,
            'agent_conversation_message_id' => $returned->id,
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $data = $this->getJson('/api/voice-sessions/'.$session->id)->assertOk()->json('data');

        $this->assertSame('channel_asserted', $data['verification_level']);
        $this->assertSame('+34600111222', $data['caller_number']);
        $this->assertSame($contact->id, $data['contact']['id']);
        $this->assertSame('I will text you the sizes we have.', $data['turns'][0]['answer_text']);
        $this->assertFalse($data['turns'][0]['transfer']);
        $this->assertCount(3, $data['conversation']['messages']);
        $this->assertSame('turn_timeout', $data['conversation']['messages'][2]['blocked_by']);
        $this->assertIsArray($data['conversation']['trace']);
        $this->assertArrayNotHasKey('bridge_session_id', $data);
        $this->assertArrayNotHasKey('disposition', $data);
        $this->assertArrayNotHasKey('duration_seconds', $data);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sessionAt(Site $site, array $overrides = []): VoiceSession
    {
        $token = VoiceBridgeToken::factory()->create(['site_id' => $site->id]);

        return VoiceSession::factory()->create(array_merge([
            'voice_bridge_token_id' => $token->id,
            'site_id' => $site->id,
            'started_at' => now(),
        ], $overrides));
    }
}
