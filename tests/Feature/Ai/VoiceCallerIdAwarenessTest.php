<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Site;
use App\Models\VoiceSession;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\PrincipalPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class VoiceCallerIdAwarenessTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function voice_create_contact_attaches_session_caller_number_when_phone_is_omitted(): void
    {
        $site = Site::factory()->create();
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'concierge', origin: AgentOrigin::Voice, channel: AgentChannel::Voice);
        VoiceSession::factory()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'caller_number' => '+34908121212',
            'site_id' => $site->id,
        ]);

        $result = $this->dispatchTool('concierge', 'crm.create_contact', $principal, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertFalse($result->data['matched']);
        $this->assertStringContainsString('attached the number this call came from', $result->display);
        $this->assertSame(0, preg_match('/\d/', $result->display));
        $this->assertStringNotContainsString('34908121212', $result->display);

        $channel = ContactChannel::query()
            ->where('type', ContactChannelType::Phone)
            ->first();
        $this->assertNotNull($channel);
        $this->assertSame('+34908121212', $channel->value);
        $this->assertSame($result->data['contact_id'], $channel->contact_id);
    }

    #[Test]
    public function voice_create_contact_without_a_session_number_creates_no_phone_channel(): void
    {
        $site = Site::factory()->create();
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'concierge', origin: AgentOrigin::Voice, channel: AgentChannel::Voice);

        $result = $this->dispatchTool('concierge', 'crm.create_contact', $principal, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertFalse($result->data['matched']);
        $this->assertStringNotContainsString('attached the number this call came from', $result->display);
        $this->assertSame(0, ContactChannel::query()->where('type', ContactChannelType::Phone)->count());
        $this->assertNotNull(Contact::query()->find($result->data['contact_id']));
    }

    #[Test]
    public function webchat_create_contact_does_not_attach_a_session_caller_number(): void
    {
        $site = Site::factory()->create();
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        VoiceSession::factory()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'caller_number' => '+34908121212',
            'site_id' => $site->id,
        ]);

        $result = $this->dispatchTool('sales', 'crm.create_contact', $principal, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada-webchat@example.com',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame(0, ContactChannel::query()->where('type', ContactChannelType::Phone)->count());
        $this->assertStringNotContainsString('attached the number this call came from', $result->display);
    }

    #[Test]
    public function voice_prompt_names_caller_id_without_the_raw_number(): void
    {
        $site = Site::factory()->create();
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'concierge', origin: AgentOrigin::Voice, channel: AgentChannel::Voice);
        VoiceSession::factory()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'caller_number' => '+34908121212',
            'site_id' => $site->id,
        ]);

        $prompt = $ctx->definition->systemPrompt($ctx);

        $this->assertStringContainsString(
            "The caller's phone is already known from caller ID. Do not ask for it. When creating a contact, omit phone — the session number will be attached.",
            $prompt,
        );
        $this->assertStringContainsString(
            'Do not ask the caller to speak an email address. When creating a contact, omit email.',
            $prompt,
        );
        $this->assertStringNotContainsString('+34908121212', $prompt);
        $this->assertStringNotContainsString('34908121212', $prompt);
    }

    #[Test]
    public function create_contact_promotion_stamps_the_voice_session_contact(): void
    {
        $site = Site::factory()->create();
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'concierge', origin: AgentOrigin::Voice, channel: AgentChannel::Voice);
        $session = VoiceSession::factory()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'caller_number' => '+34908121212',
            'contact_id' => null,
            'site_id' => $site->id,
        ]);

        $result = $this->dispatchTool('concierge', 'crm.create_contact', $principal, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);

        $promoted = PrincipalPromotion::afterToolResult(
            $ctx->conversation,
            $principal,
            'crm.create_contact',
            $result,
            $ctx,
        );

        $this->assertNotNull($promoted);
        $this->assertSame($result->resultId, $ctx->conversation->fresh()->contact_id);
        $this->assertSame($result->resultId, $session->fresh()->contact_id);
    }
}
