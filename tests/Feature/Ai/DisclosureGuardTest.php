<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Guards\DisclosureGuard;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\Ai\RecordingTool;
use Tests\Support\Ai\TestAgentDefinition;
use Tests\TestCase;

class DisclosureGuardTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    private FakeModelDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);
        app(ToolRegistry::class)->register(new RecordingTool);
        app(AgentRegistry::class)->register(new TestAgentDefinition);
    }

    #[Test]
    public function leak_at_channel_asserted_of_verified_era_fact_is_blocked(): void
    {
        $contact = Contact::factory()->create();
        $agent = AiAgent::factory()->create(['key' => 'support', 'name' => 'Support', 'is_active' => true]);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'contact_id' => $contact->id,
            'verification_level' => VerificationLevel::ChannelAsserted,
            'locale' => 'en',
        ]);

        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => 1,
            'role' => AgentMessageRole::Assistant,
            'content' => 'Your open balance is €50.00.',
            'fact_keys' => (new FactBag)->money('50.00', 'EUR')->all(),
            'principal_verification' => VerificationLevel::Verified->value,
        ]);

        $verdict = app(DisclosureGuard::class)->check(
            'Your open balance is €50.00.',
            new FactBag,
            new AgentContext(
                $conversation->principal(),
                ChannelProfile::for($conversation->channel),
                app(AgentRegistry::class)->get('support'),
                $conversation,
                $agent,
            ),
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('disclosure', $verdict->blockedBy);
        $this->assertSame(HandoffReason::VerificationRequired, $verdict->handoffReason);
    }

    #[Test]
    public function disclosure_is_appended_on_the_first_customer_turn_only(): void
    {
        $conversation = $this->conversation();
        $phrase = (string) config('ai-handoff.disclosure.en');

        $this->driver->enqueueText('Hello.');
        $first = app(AgentRuntime::class)->turn($conversation, $conversation->principal(), 'hi there');
        $this->assertStringContainsString($phrase, $first->draft);

        $this->driver->enqueueText('Still here.');
        $second = app(AgentRuntime::class)->turn($conversation->fresh(), $conversation->principal(), 'and you');
        $this->assertSame('Still here.', $second->draft);
        $this->assertStringNotContainsString($phrase, $second->draft);
    }

    #[Test]
    public function first_turn_rule_match_canned_line_includes_disclosure(): void
    {
        $conversation = $this->conversation();
        $phrase = (string) config('ai-handoff.disclosure.en');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'I got a letter about an auction',
        );

        $this->assertSame(0, $this->driver->callCount);
        $this->assertStringContainsString($phrase, $turn->draft);
        $this->assertStringContainsString('teammate', $turn->draft);
    }

    #[Test]
    public function verified_follow_up_may_restate_prior_balance(): void
    {
        $conversation = $this->conversation();

        $this->driver
            ->enqueueToolCalls([['name' => 'test.record', 'id' => 'c1', 'arguments' => []]])
            ->enqueueText('The figure is €84,70 (incl. 21% IVA).');

        app(AgentRuntime::class)->turn($conversation, $conversation->principal(), 'How much is it?');

        $this->driver->enqueueText('The figure is still €84,70.');
        $second = app(AgentRuntime::class)->turn(
            $conversation->fresh(),
            $conversation->principal(),
            "when's that due?",
        );

        $this->assertNull($second->handoff);
        $this->assertNull($second->blockedBy);
        $this->assertStringContainsString('84,70', $second->draft);
    }

    private function conversation(): AgentConversation
    {
        $agent = AiAgent::factory()->create([
            'key' => 'test',
            'name' => 'test',
            'is_active' => true,
        ]);

        return AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
        ]);
    }
}
