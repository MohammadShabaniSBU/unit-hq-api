<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\DisclosureSentence;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Guards\DisclosureGuard;
use App\Support\Ai\Guards\DraftToken;
use App\Support\Ai\Guards\DraftTokenExtractor;
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
        $phrase = DisclosureSentence::for('en');

        $this->driver->enqueueText('Hello.');
        $first = app(AgentRuntime::class)->turn($conversation, $conversation->principal(), 'hi there');
        $this->assertStringStartsWith($phrase, $first->draft);

        $this->driver->enqueueText('Still here.');
        $second = app(AgentRuntime::class)->turn($conversation->fresh(), $conversation->principal(), 'and you');
        $this->assertSame('Still here.', $second->draft);
        $this->assertStringNotContainsString($phrase, $second->draft);
    }

    #[Test]
    public function first_turn_rule_match_canned_line_includes_disclosure(): void
    {
        $conversation = $this->conversation();
        $phrase = DisclosureSentence::for('en');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'I got a letter about an auction',
        );

        $this->assertSame(0, $this->driver->callCount);
        $this->assertStringStartsWith($phrase, $turn->draft);
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

    #[Test]
    public function licensed_offer_url_survives_disclosure_at_anonymous(): void
    {
        $token = str_repeat('a1', 32);
        $url = 'http://localhost:3000/preview/offer/'.$token;
        $extracted = app(DraftTokenExtractor::class)->extract($url);
        $identifierTypes = array_values(array_filter(
            $extracted,
            static fn (DraftToken $draft): bool => $draft->type === DraftToken::Identifier,
        ));
        $this->assertSame([], $identifierTypes);

        $facts = (new FactBag)->identifier($url)->absorb("Public link: {$url}.");
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');

        $verdict = app(DisclosureGuard::class)->check(
            "Here is your offer: {$url}",
            $facts,
            $ctx,
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function first_turn_without_the_sentence_prepends_and_marks_appended(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $phrase = DisclosureSentence::for('en');

        $verdict = app(DisclosureGuard::class)->check('Hello there.', new FactBag, $ctx);

        $this->assertTrue($verdict->passed);
        $this->assertSame($phrase.' Hello there.', $verdict->mutatedDraft);
        $this->assertTrue($verdict->events[0]['detail']['prompted'] ?? false);
        $this->assertTrue($verdict->events[0]['detail']['appended'] ?? false);
    }

    #[Test]
    public function first_turn_with_the_sentence_does_not_mutate(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $phrase = DisclosureSentence::for('en');
        $draft = $phrase." How can I help?\n";

        $verdict = app(DisclosureGuard::class)->check($draft, new FactBag, $ctx);

        $this->assertTrue($verdict->passed);
        $this->assertNull($verdict->mutatedDraft);
        $this->assertTrue($verdict->events[0]['detail']['prompted'] ?? false);
        $this->assertFalse($verdict->events[0]['detail']['appended'] ?? true);
    }

    #[Test]
    public function second_turn_does_not_emit_prompted_detail(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'sequence' => 1,
            'role' => AgentMessageRole::Assistant,
            'content' => 'Prior reply.',
        ]);

        $verdict = app(DisclosureGuard::class)->check('Still here.', new FactBag, $ctx);

        $this->assertTrue($verdict->passed);
        $this->assertNull($verdict->mutatedDraft);
        $this->assertArrayNotHasKey('detail', $verdict->events[0]);
        $this->assertStringNotContainsString(DisclosureSentence::for('en'), 'Still here.');
    }

    #[Test]
    public function resolved_sentence_fits_the_sms_budget_at_the_default_company(): void
    {
        foreach (['en', 'es', 'fr'] as $locale) {
            $sentence = DisclosureSentence::for($locale);
            $this->assertNotSame('', $sentence);
            $this->assertLessThanOrEqual(60, mb_strlen($sentence), $locale.': '.$sentence);
        }
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
