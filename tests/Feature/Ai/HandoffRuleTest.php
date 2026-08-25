<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\Offer;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffRuleKey;
use App\Support\Ai\Guards\HandoffRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandoffRuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_rule_key_fires_on_en_and_es_corpus(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'name' => 'Support']);
        $rules = app(HandoffRules::class);

        foreach (['en', 'es'] as $locale) {
            $corpus = require base_path("tests/Fixtures/agents/handoff/{$locale}.php");
            $conversation = AgentConversation::factory()->create([
                'ai_agent_id' => $agent->id,
                'locale' => $locale,
            ]);

            foreach (HandoffRuleKey::cases() as $key) {
                $this->assertNotEmpty(
                    $corpus[$key->value] ?? [],
                    "Missing {$locale} corpus for {$key->value}",
                );

                foreach ($corpus[$key->value] as $input) {
                    $match = $rules->match($conversation, $conversation->principal(), $input);
                    $this->assertNotNull($match, "Expected [{$key->value}] to fire on {$locale}: {$input}");
                    $this->assertSame($key->reason(), $match->reason, $input);
                    $this->assertSame($key->value, $match->detail['rule'] ?? null, $input);
                }
            }
        }
    }

    #[Test]
    public function benign_near_misses_do_not_fire(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'support', 'name' => 'Support']);
        $rules = app(HandoffRules::class);

        foreach (['en', 'es'] as $locale) {
            $corpus = require base_path("tests/Fixtures/agents/handoff/{$locale}.php");
            $conversation = AgentConversation::factory()->create([
                'ai_agent_id' => $agent->id,
                'locale' => $locale,
            ]);

            foreach ($corpus['benign'] as $input) {
                $this->assertNull(
                    $rules->match($conversation, $conversation->principal(), $input),
                    "Benign {$locale} input should not hand off: {$input}",
                );
            }
        }
    }

    #[Test]
    public function open_delinquency_trips_without_keywords(): void
    {
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $contact->id]);
        Delinquency::factory()->create(['contract_id' => $contract->id, 'cured_on' => null]);

        $agent = AiAgent::factory()->create(['key' => 'support', 'name' => 'Support']);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'contact_id' => $contact->id,
            'locale' => 'en',
        ]);

        $match = app(HandoffRules::class)->match(
            $conversation,
            $conversation->principal(),
            'Hello, what are your hours?',
        );

        $this->assertNotNull($match);
        $this->assertSame(HandoffReason::Delinquency, $match->reason);
        $this->assertSame(HandoffRuleKey::Delinquency->value, $match->detail['rule'] ?? null);
        $this->assertSame('open_delinquency', $match->detail['matched'] ?? null);
    }

    #[Test]
    public function french_lists_are_present_and_untested(): void
    {
        $fr = config('ai-handoff.rules.fr');
        $this->assertIsArray($fr);
        foreach (HandoffRuleKey::cases() as $key) {
            $this->assertArrayHasKey($key->value, $fr);
            $this->assertNotEmpty($fr[$key->value]);
        }
    }

    #[Test]
    public function a_rule_match_does_not_call_the_model(): void
    {
        $driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $driver);

        $agent = AiAgent::factory()->create(['key' => 'support', 'name' => 'Support', 'is_active' => true]);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'locale' => 'en',
        ]);

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'I got a letter about an auction',
        );

        $this->assertSame(0, $driver->callCount);
        $this->assertSame(HandoffReason::LegalOrComplaint, $turn->handoff?->reason);
    }

    #[Test]
    public function price_negotiation_still_escalates_when_the_sales_offer_tool_is_present(): void
    {
        $driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $driver);

        $agent = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales', 'is_active' => true]);
        $conversation = AgentConversation::factory()->anonymous()->create([
            'ai_agent_id' => $agent->id,
            'locale' => 'en',
        ]);

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Can you do cheaper than that?',
        );

        $this->assertSame(0, $driver->callCount);
        $this->assertSame(HandoffReason::PriceNegotiation, $turn->handoff?->reason);
        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function delinquency_still_escalates_when_sales_write_tools_are_present(): void
    {
        $driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $driver);

        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $contact->id]);
        Delinquency::factory()->create(['contract_id' => $contract->id, 'cured_on' => null]);

        $agent = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales', 'is_active' => true]);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'contact_id' => $contact->id,
            'locale' => 'en',
        ]);

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Hello, what are your hours?',
        );

        $this->assertSame(0, $driver->callCount);
        $this->assertSame(HandoffReason::Delinquency, $turn->handoff?->reason);
    }
}
