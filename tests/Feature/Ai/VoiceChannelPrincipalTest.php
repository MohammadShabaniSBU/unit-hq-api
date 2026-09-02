<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\ContactChannelType;
use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\SystemEvent;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\PrincipalPromotion;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolResult;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class VoiceChannelPrincipalTest extends TestCase
{
    use DispatchesAgentTools;
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function voice_origin_opens_a_customer_conversation_and_stamps_origin(): void
    {
        $agent = $this->liveAgent();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Voice->value,
            'origin' => AgentOrigin::Voice->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.channel', AgentChannel::Voice->value)
            ->assertJsonPath('data.origin', AgentOrigin::Voice->value)
            ->assertJsonPath('data.audience', AgentAudience::Customer->value)
            ->assertJsonPath('data.verification_level', VerificationLevel::Anonymous->value)
            ->assertJsonPath('data.contact_id', null);
    }

    #[Test]
    public function voice_without_contact_is_customer_anonymous_not_internal(): void
    {
        $agent = $this->liveAgent();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $id = $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Voice->value,
            'origin' => AgentOrigin::Voice->value,
        ])->assertCreated()->json('data.id');

        $conversation = AgentConversation::query()->findOrFail($id);
        $this->assertSame(AgentAudience::Customer, $conversation->audience);
        $this->assertSame(VerificationLevel::Anonymous, $conversation->verification_level);
        $this->assertNull($conversation->contact_id);
        $this->assertNull($conversation->employee_id);
    }

    #[Test]
    public function unique_caller_number_is_channel_asserted(): void
    {
        $agent = $this->liveAgent();
        $contact = Contact::factory()->create();
        $this->givePhone($contact, '+34911000001');
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Voice->value,
            'origin' => AgentOrigin::Voice->value,
            'caller_number' => '+34 911 000 001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.audience', AgentAudience::Customer->value)
            ->assertJsonPath('data.verification_level', VerificationLevel::ChannelAsserted->value)
            ->assertJsonPath('data.contact_id', $contact->id);
    }

    #[Test]
    public function ambiguous_caller_number_is_anonymous_and_logs_the_event(): void
    {
        $agent = $this->liveAgent();
        $this->givePhone(Contact::factory()->create(), '+34911000001');
        $this->givePhone(Contact::factory()->create(), '+34911000001');
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $id = $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Voice->value,
            'origin' => AgentOrigin::Voice->value,
            'caller_number' => '+34911000001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.audience', AgentAudience::Customer->value)
            ->assertJsonPath('data.verification_level', VerificationLevel::Anonymous->value)
            ->assertJsonPath('data.contact_id', null)
            ->json('data.id');

        $event = SystemEvent::query()->where('event', 'ai.voice.caller_ambiguous')->first();
        $this->assertNotNull($event);
        $this->assertSame((int) $id, $event->subject_id);
        $this->assertSame(2, $event->payload['matches'] ?? null);
        $this->assertArrayNotHasKey('caller_number', $event->payload ?? []);
    }

    #[Test]
    public function withheld_caller_id_opens_an_anonymous_conversation(): void
    {
        $agent = $this->liveAgent();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        foreach (['', 'anonymous', 'withheld'] as $callerNumber) {
            $this->postJson('/api/agent-conversations', [
                'agent_key' => $agent->key,
                'channel' => AgentChannel::Voice->value,
                'origin' => AgentOrigin::Voice->value,
                'caller_number' => $callerNumber,
            ])
                ->assertCreated()
                ->assertJsonPath('data.audience', AgentAudience::Customer->value)
                ->assertJsonPath('data.verification_level', VerificationLevel::Anonymous->value)
                ->assertJsonPath('data.contact_id', null);
        }
    }

    #[Test]
    public function voice_origin_rejects_a_submitted_verified_level(): void
    {
        $agent = $this->liveAgent();
        $contact = Contact::factory()->create();
        $this->givePhone($contact, '+34911000002');
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Voice->value,
            'origin' => AgentOrigin::Voice->value,
            'caller_number' => '+34911000002',
            'verification_level' => VerificationLevel::Verified->value,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('verification_level');
    }

    #[Test]
    public function voice_origin_rejects_a_client_supplied_contact_id(): void
    {
        $agent = $this->liveAgent();
        $contact = Contact::factory()->create();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Voice->value,
            'origin' => AgentOrigin::Voice->value,
            'contact_id' => $contact->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_id');
    }

    #[Test]
    public function verify_code_promotion_does_not_raise_live_voice_above_channel_asserted(): void
    {
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::channelAsserted($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'concierge', origin: AgentOrigin::Voice);
        $ctx->conversation->forceFill([
            'channel' => AgentChannel::Voice,
        ])->save();

        $ok = ToolResult::ok(['ok' => true, 'reason' => 'verified'], 'Identity verified.', new FactBag);
        $promoted = PrincipalPromotion::afterToolResult(
            $ctx->conversation->fresh(),
            $principal,
            'identity.verify_code',
            $ok,
            $ctx,
        );

        $this->assertNull($promoted);
        $this->assertSame(
            VerificationLevel::ChannelAsserted,
            $ctx->conversation->fresh()->verification_level,
        );
    }

    #[Test]
    public function inbox_without_contact_is_customer(): void
    {
        $agent = $this->liveAgent();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $id = $this->postJson('/api/agent-conversations', [
            'agent_key' => $agent->key,
            'channel' => AgentChannel::Email->value,
            'origin' => AgentOrigin::Inbox->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.audience', AgentAudience::Customer->value)
            ->assertJsonPath('data.contact_id', null)
            ->json('data.id');

        $conversation = AgentConversation::query()->findOrFail($id);
        $this->assertNull($conversation->employee_id);
    }

    private function liveAgent(): AiAgent
    {
        return AiAgent::factory()->create(['key' => 'concierge', 'is_active' => true]);
    }

    private function givePhone(Contact $contact, string $number): ContactChannel
    {
        return ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => $number,
            'is_primary' => true,
            'opted_in' => true,
        ]);
    }
}
