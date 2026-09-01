<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\AgentConversation;
use App\Models\AgentPrincipalPromotion;
use App\Models\Contact;
use App\Models\ContactVerification;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Identity\VerificationChallenge;
use App\Support\Ai\PrincipalPromotion;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolResult;
use App\Support\Ai\Trace\TraceAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class PrincipalPromotionOtpTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    #[Test]
    public function successful_verify_promotes_the_conversation_and_unlocks_billing_balance(): void
    {
        $employee = Employee::factory()->create();
        [$contact, $principal, $ctx] = $this->assertedWorld($employee);
        $channel = $this->givePrimaryEmail($contact, 'tenant@example.com');
        $this->openChallenge($contact->id, $channel->id, '847291');

        $verify = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '847291',
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $verify->status);

        $invocation = $this->recordInvocation(
            $ctx,
            'identity.verify_code',
            ['code' => '847291'],
            $verify,
            $principal,
        );
        $invocation->forceFill(['turn' => 1, 'seq' => 1])->save();

        $promoted = PrincipalPromotion::afterToolResult(
            $ctx->conversation,
            $principal,
            'identity.verify_code',
            $verify,
            $ctx,
            $invocation,
        );

        $this->assertNotNull($promoted);
        $this->assertSame(VerificationLevel::Verified, $promoted->verification);
        $this->assertSame($contact->id, $promoted->contactId);

        $ctx->conversation->refresh();
        $this->assertSame(VerificationLevel::Verified, $ctx->conversation->verification_level);

        $activity = Activity::query()
            ->where('log_name', LogChannel::Ai->value)
            ->where('description', 'agent.conversation.principal_promoted')
            ->where('subject_id', $ctx->conversation->id)
            ->first();
        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('channel_asserted', $properties['from'] ?? null);
        $this->assertSame('verified', $properties['to'] ?? null);
        $this->assertSame($contact->id, $properties['contact_id'] ?? null);
        $this->assertSame('otp', $properties['method'] ?? null);

        $row = AgentPrincipalPromotion::query()
            ->where('agent_conversation_id', $ctx->conversation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(VerificationLevel::ChannelAsserted, $row->from_level);
        $this->assertSame(VerificationLevel::Verified, $row->to_level);
        $this->assertSame('otp', $row->method);
        $this->assertSame(1, $row->turn);
        $this->assertGreaterThan($invocation->seq, $row->seq);

        $trace = TraceAssembler::for($ctx->conversation->fresh());
        $promotion = collect($trace)->firstWhere('kind', 'promotion');
        $this->assertIsArray($promotion);
        $this->assertSame('channel_asserted', $promotion['from'] ?? null);
        $this->assertSame('verified', $promotion['to'] ?? null);
        $this->assertSame('otp', $promotion['method'] ?? null);
        $this->assertSame(1, $promotion['turn'] ?? null);

        $tool = collect($trace)->first(
            fn (array $entry): bool => ($entry['kind'] ?? null) === 'tool'
                && ($entry['tool_key'] ?? null) === 'identity.verify_code',
        );
        $this->assertIsArray($tool);
        $this->assertSame($tool['turn'] ?? null, $promotion['turn'] ?? 'missing');

        Sanctum::actingAs($employee);
        $response = $this->getJson("/api/agent-conversations/{$ctx->conversation->id}")
            ->assertOk();
        $apiPromotion = collect($response->json('data.trace'))->firstWhere('kind', 'promotion');
        $this->assertIsArray($apiPromotion);
        $this->assertSame('otp', $apiPromotion['method'] ?? null);
        $this->assertSame('channel_asserted', $apiPromotion['from'] ?? null);
        $this->assertSame('verified', $apiPromotion['to'] ?? null);

        $promotedCtx = $ctx->withPrincipal($promoted);
        $balance = $this->dispatchTool('concierge', 'billing.balance', $promoted, [], $promotedCtx);
        $this->assertSame(ToolInvocationStatus::Ok, $balance->status);
    }

    #[Test]
    public function a_conversation_cannot_verify_into_a_different_contact(): void
    {
        $owner = Contact::factory()->create();
        $stranger = Contact::factory()->create();
        $principal = AgentPrincipal::channelAsserted($stranger->id, null, 'en');
        $ctx = $this->writeContext($principal, 'concierge');
        $ctx->conversation->forceFill(['contact_id' => $owner->id])->save();

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
    public function a_verified_conversation_does_not_verify_a_second_conversation_for_the_same_contact(): void
    {
        [$contact, $principal, $ctx] = $this->assertedWorld();
        $channel = $this->givePrimaryEmail($contact, 'once@example.com');
        $this->openChallenge($contact->id, $channel->id, '111111');

        $verify = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '111111',
        ], $ctx);
        PrincipalPromotion::afterToolResult(
            $ctx->conversation,
            $principal,
            'identity.verify_code',
            $verify,
            $ctx,
        );
        $this->assertSame(VerificationLevel::Verified, $ctx->conversation->fresh()->verification_level);

        $other = AgentConversation::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'audience' => AgentAudience::Customer,
            'contact_id' => $contact->id,
            'verification_level' => VerificationLevel::ChannelAsserted,
            'employee_id' => null,
        ]);

        $this->assertSame(VerificationLevel::ChannelAsserted, $other->verification_level);
        $this->assertSame(VerificationLevel::ChannelAsserted, $other->principal()->verification);
        $this->assertNotSame($ctx->conversation->id, $other->id);
    }

    #[Test]
    public function a_failed_verify_does_not_promote(): void
    {
        [$contact, $principal, $ctx] = $this->assertedWorld();
        $channel = $this->givePrimaryEmail($contact, 'nope@example.com');
        $this->openChallenge($contact->id, $channel->id, '555555');

        $failed = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '000000',
        ], $ctx);

        $promoted = PrincipalPromotion::afterToolResult(
            $ctx->conversation,
            $principal,
            'identity.verify_code',
            $failed,
            $ctx,
        );

        $this->assertNull($promoted);
        $this->assertSame(VerificationLevel::ChannelAsserted, $ctx->conversation->fresh()->verification_level);
        $this->assertSame(0, Activity::query()->where('description', 'agent.conversation.principal_promoted')->count());
        $this->assertSame(0, AgentPrincipalPromotion::query()->count());
    }

    /**
     * @return array{0: Contact, 1: AgentPrincipal, 2: AgentContext}
     */
    private function assertedWorld(?Employee $employee = null): array
    {
        $this->fakeCommunicationProviders();
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::channelAsserted($contact->id, $site->id, 'en');
        $ctx = $this->writeContext($principal, 'concierge', $employee);

        return [$contact, $principal, $ctx];
    }

    private function openChallenge(int $contactId, int $channelId, string $code): ContactVerification
    {
        return ContactVerification::query()->create([
            'contact_id' => $contactId,
            'contact_channel_id' => $channelId,
            'code_hash' => VerificationChallenge::hash($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);
    }
}
