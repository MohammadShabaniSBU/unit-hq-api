<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\StayPeriod;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Site;
use App\Models\UnitClass;
use App\Support\Ai\ContextWindow;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\FactRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContextWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'agents.context.recent_turns' => 4,
            'agents.context.max_history_chars' => 6_000,
        ]);
    }

    #[Test]
    public function twelve_turn_history_keeps_four_verbatim_pairs_drops_old_tools_and_summarises_refs(): void
    {
        $registry = (new FactRegistry)->absorb(
            EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
            EntityRef::of(EntityType::UnitClass, 8, 'Trastero 12 m²'),
            EntityRef::of(EntityType::UnitClass, 12, 'Trastero 16 m²'),
            EntityRef::of(EntityType::Contact, 833, 'Mohammad'),
            EntityRef::of(EntityType::Deal, 812, 'deal 812'),
        );

        $result = ContextWindow::build($this->history(12), $registry);
        $sent = ContextWindow::withoutInternalKeys($result->messages);

        $windowStart = $this->nthFromLastUserIndex($sent, 4);
        $this->assertNotNull($windowStart);

        $verbatimPairs = 0;
        foreach ($sent as $i => $message) {
            if (($message['role'] ?? '') === 'tool') {
                $this->assertGreaterThanOrEqual($windowStart, $i, 'tool message older than the window');
            }
            if ($i < $windowStart && ($message['role'] ?? '') === 'assistant') {
                $this->assertTrue(($message['tool_calls'] ?? []) === []);
            }
            if ($i >= $windowStart && ($message['role'] ?? '') === 'assistant' && ($message['tool_calls'] ?? []) !== []) {
                $verbatimPairs++;
            }
        }
        $this->assertLessThanOrEqual(4, $verbatimPairs);

        $summaries = array_values(array_filter(
            $sent,
            static fn (array $message): bool => ($message['role'] ?? '') === 'system'
                && str_starts_with((string) ($message['content'] ?? ''), 'Earlier in this conversation:'),
        ));
        $this->assertCount(1, $summaries);
        $summary = (string) $summaries[0]['content'];
        $this->assertStringContainsString('Refs:', $summary);
        $this->assertStringContainsString('site 1 = Madrid Centro', $summary);
        $this->assertStringContainsString('unit_class 8 = Trastero 12 m²', $summary);
        $this->assertStringContainsString('unit_class 12 = Trastero 16 m²', $summary);
        $this->assertStringContainsString('contact 833 = Mohammad', $summary);
        $this->assertStringContainsString('deal 812 = deal 812', $summary);
        $this->assertGreaterThan(0, $result->messagesEvicted);
        $this->assertGreaterThan(0, $result->summaryChars);
        $this->assertLessThanOrEqual(8_000, $result->estimatedTokens);
    }

    #[Test]
    public function build_is_idempotent_on_the_same_input_and_on_its_own_output(): void
    {
        $registry = (new FactRegistry)->absorb(
            EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
        );
        $history = $this->history(8);

        $first = ContextWindow::build($history, $registry);
        $second = ContextWindow::build($history, $registry);
        $this->assertSame($first->messages, $second->messages);

        $third = ContextWindow::build($first->messages, $registry);
        $this->assertSame(
            ContextWindow::withoutInternalKeys($first->messages),
            ContextWindow::withoutInternalKeys($third->messages),
        );
    }

    #[Test]
    public function evicted_assistants_do_not_keep_orphaned_tool_calls(): void
    {
        $registry = new FactRegistry;
        $sent = ContextWindow::withoutInternalKeys(
            ContextWindow::build($this->history(6), $registry)->messages,
        );

        $keptCallIds = [];
        foreach ($sent as $message) {
            if (($message['role'] ?? '') !== 'assistant') {
                continue;
            }
            foreach ($message['tool_calls'] ?? [] as $call) {
                if (is_array($call) && isset($call['id'])) {
                    $keptCallIds[] = (string) $call['id'];
                }
            }
        }

        foreach ($sent as $message) {
            if (($message['role'] ?? '') !== 'tool') {
                continue;
            }
            $id = (string) ($message['tool_call_id'] ?? '');
            $this->assertContains($id, $keptCallIds, "orphaned tool_call_id {$id}");
        }

        $windowStart = $this->nthFromLastUserIndex($sent, 4);
        $this->assertNotNull($windowStart);
        foreach ($sent as $i => $message) {
            if ($i < $windowStart && ($message['role'] ?? '') === 'assistant') {
                $this->assertSame([], $message['tool_calls'] ?? []);
            }
        }
    }

    #[Test]
    public function character_budget_evicts_assistant_text_before_user_text(): void
    {
        config(['agents.context.max_history_chars' => 40]);

        $messages = [
            ['role' => 'system', 'content' => 'prompt'],
            ['role' => 'user', 'content' => 'need a unit'],
            ['role' => 'assistant', 'content' => str_repeat('A', 80), 'tool_calls' => []],
            ['role' => 'user', 'content' => 'need a unit two'],
            ['role' => 'assistant', 'content' => str_repeat('B', 80), 'tool_calls' => []],
            ['role' => 'user', 'content' => 'recent one'],
            ['role' => 'assistant', 'content' => 'recent reply', 'tool_calls' => []],
            ['role' => 'user', 'content' => 'recent two'],
            ['role' => 'assistant', 'content' => 'recent reply two', 'tool_calls' => []],
            ['role' => 'user', 'content' => 'recent three'],
            ['role' => 'assistant', 'content' => 'recent reply three', 'tool_calls' => []],
            ['role' => 'user', 'content' => 'recent four'],
        ];

        $sent = ContextWindow::withoutInternalKeys(
            ContextWindow::build($messages, new FactRegistry)->messages,
        );

        $olderText = [];
        $windowStart = $this->nthFromLastUserIndex($sent, 4);
        $this->assertNotNull($windowStart);
        foreach ($sent as $i => $message) {
            if ($i < $windowStart && in_array($message['role'] ?? '', ['user', 'assistant'], true)) {
                $olderText[] = $message;
            }
        }

        $roles = array_map(static fn (array $message): string => (string) $message['role'], $olderText);
        $this->assertNotContains('assistant', $roles);
        $this->assertContains('user', $roles);
    }

    #[Test]
    public function empty_assistant_after_stripping_tool_calls_is_dropped(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'prompt'],
            ['role' => 'user', 'content' => 'old'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 'facility.availability', 'id' => 'c1', 'arguments' => []]]],
            ['role' => 'tool', 'content' => 'three units', 'tool_call_id' => 'c1'],
            ['role' => 'user', 'content' => 'u1'],
            ['role' => 'user', 'content' => 'u2'],
            ['role' => 'user', 'content' => 'u3'],
            ['role' => 'user', 'content' => 'u4'],
        ];

        $sent = ContextWindow::withoutInternalKeys(
            ContextWindow::build($messages, new FactRegistry)->messages,
        );

        foreach ($sent as $message) {
            if (($message['role'] ?? '') === 'assistant') {
                $this->fail('empty assistant outside the window should have been dropped');
            }
        }
    }

    #[Test]
    public function stated_needs_come_from_the_deal_row_not_the_transcript(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Norte']);
        $class = UnitClass::factory()->create(['label' => 'Trastero 12 m²']);
        $contact = Contact::factory()->create(['first_name' => 'Mohammad']);
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'expected_move_in' => '2026-08-31',
            'expected_stay_length' => 6,
            'expected_stay_period' => StayPeriod::Month,
            'desired_size' => '12.00',
            'desired_unit_class_id' => $class->id,
        ]);
        $deal->load(['contact', 'site', 'desiredUnitClass']);

        $registry = (new FactRegistry)->absorb(EntityRef::deal($deal));
        $summary = $this->summaryOf($this->history(6), $registry, $deal);

        $this->assertStringContainsString('Stated needs:', $summary);
        $this->assertStringContainsString('Mohammad', $summary);
        $this->assertStringContainsString('site Madrid Norte', $summary);
        $this->assertStringContainsString('move-in 2026-08-31', $summary);
        $this->assertStringContainsString('stay 6 month', $summary);
        $this->assertStringContainsString('size 12.00 m²', $summary);
        $this->assertStringContainsString('class Trastero 12 m²', $summary);
        $this->assertStringNotContainsString('user turn', $summary);
    }

    #[Test]
    public function prices_quoted_keep_the_most_recent_display_per_tool_and_class(): void
    {
        $history = $this->history(6);
        $stamped = 0;
        foreach ($history as $i => $message) {
            if (($message['role'] ?? '') !== 'tool') {
                continue;
            }
            $history[$i][ContextWindow::QUOTES_KEY] = [[
                'tool_key' => 'pricing.quote',
                'unit_class_id' => 8,
                'display' => $stamped === 0
                    ? '€70.00 / month for Trastero 8 m²'
                    : '€166.91 / month for Trastero 12 m²',
            ]];
            $stamped++;
            if ($stamped === 2) {
                break;
            }
        }

        $summary = $this->summaryOf($history, new FactRegistry);
        $this->assertStringContainsString('Prices quoted earlier:', $summary);
        $this->assertStringContainsString('€166.91 / month for Trastero 12 m²', $summary);
        $this->assertStringNotContainsString('€70.00', $summary);
    }

    #[Test]
    public function short_history_does_not_insert_a_summary(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'prompt'],
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi', 'tool_calls' => []],
            ['role' => 'user', 'content' => 'now'],
        ];

        $sent = ContextWindow::withoutInternalKeys(
            ContextWindow::build($messages, new FactRegistry)->messages,
        );

        foreach ($sent as $message) {
            $this->assertStringNotContainsString(
                'Earlier in this conversation:',
                (string) ($message['content'] ?? ''),
            );
        }
        $this->assertSame(0, ContextWindow::build($messages, new FactRegistry)->messagesEvicted);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function history(int $turns): array
    {
        $messages = [['role' => 'system', 'content' => 'You are the sales agent.']];
        for ($i = 1; $i <= $turns; $i++) {
            $callId = 'call_'.$i;
            $messages[] = ['role' => 'user', 'content' => "user turn {$i}"];
            $messages[] = [
                'role' => 'assistant',
                'content' => "assistant turn {$i}",
                'tool_calls' => [['name' => 'facility.availability', 'id' => $callId, 'arguments' => []]],
            ];
            $tool = [
                'role' => 'tool',
                'content' => "availability {$i}",
                'tool_call_id' => $callId,
                'tool_name' => 'facility.availability',
            ];
            if ($i === 1) {
                $tool[ContextWindow::QUOTES_KEY] = [[
                    'tool_key' => 'pricing.quote',
                    'unit_class_id' => 8,
                    'display' => '€166.91 / month for Trastero 12 m²',
                ]];
            }
            $messages[] = $tool;
        }

        return $messages;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function nthFromLastUserIndex(array $messages, int $n): ?int
    {
        $indexes = [];
        foreach ($messages as $i => $message) {
            if (($message['role'] ?? '') === 'user') {
                $indexes[] = $i;
            }
        }
        if (count($indexes) < $n) {
            return null;
        }

        return $indexes[count($indexes) - $n];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function summaryOf(array $messages, FactRegistry $registry, ?Deal $deal = null): string
    {
        $sent = ContextWindow::withoutInternalKeys(
            ContextWindow::build($messages, $registry, $deal)->messages,
        );
        foreach ($sent as $message) {
            if (($message['role'] ?? '') === 'system' && str_contains((string) ($message['content'] ?? ''), 'Earlier in this conversation:')) {
                return (string) $message['content'];
            }
            if (($message['role'] ?? '') === 'system' && str_contains((string) ($message['content'] ?? ''), 'Stated needs:')) {
                return (string) $message['content'];
            }
            if (($message['role'] ?? '') === 'system' && str_contains((string) ($message['content'] ?? ''), 'Prices quoted earlier:')) {
                return (string) $message['content'];
            }
        }

        $this->fail('expected a rolling summary');
    }
}
