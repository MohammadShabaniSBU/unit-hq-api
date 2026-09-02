<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiAgent;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Guards\ChannelGuard;
use App\Support\Ai\Guards\GuardrailVerdict;
use App\Support\Ai\Tools\FactBag;
use App\Support\Communications\Gsm7Transliterator;
use App\Support\Communications\Messages\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelGuardTest extends TestCase
{
    use RefreshDatabase;

    private FakeModelDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);
    }

    #[Test]
    public function gsm7_and_ucs2_segment_counts_delegate_to_sms_message(): void
    {
        $gsm = new SmsMessage('+100', str_repeat('a', 160));
        $this->assertSame('gsm7', $gsm->encoding());
        $this->assertSame(1, $gsm->segmentCount());

        $gsmConcat = new SmsMessage('+100', str_repeat('a', 161));
        $this->assertSame(2, $gsmConcat->segmentCount());

        $accent = new SmsMessage('+100', 'café');
        $this->assertSame('gsm7', $accent->encoding());

        $ucs = new SmsMessage('+100', 'あ');
        $this->assertSame('ucs2', $ucs->encoding());
        $this->assertSame(1, $ucs->segmentCount());
    }

    #[Test]
    public function trace40_standin_transliterates_to_gsm7_inside_the_ceiling(): void
    {
        $draft = $this->ucs2SevenSegmentDraft();
        $this->assertSame('ucs2', (new SmsMessage('x', $draft))->encoding());
        $this->assertSame(7, (new SmsMessage('x', $draft))->segmentCount());

        $this->driver->enqueueText($draft);
        $events = [];
        $conversation = $this->conversation(AgentChannel::Sms);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hi',
            $this->collectGuardrail($events),
        );

        $this->assertNull($turn->handoff);
        $this->assertNull($turn->blockedBy);
        $this->assertStringNotContainsString('m²', $turn->draft);
        $this->assertStringNotContainsString('€', $turn->draft);
        $this->assertStringContainsString('m2', $turn->draft);

        $channel = $this->lastChannelEvent($events);
        $this->assertTrue($channel['detail']['gsm7_transliterated'] ?? false);
        $this->assertArrayNotHasKey('original_body', $channel['detail'] ?? []);
        $this->assertSame('gsm7', $channel['detail']['encoding'] ?? null);
        $this->assertLessThanOrEqual(
            (int) config('agents.channel.sms.max_segments'),
            $channel['detail']['segments'] ?? 99,
        );

        $stored = $this->lastAssistantMessage($conversation);
        $this->assertSame($turn->draft, $stored?->content);
        $this->assertStringNotContainsString('m²', $stored?->content ?? '');
    }

    #[Test]
    public function sms_over_ceiling_retries_then_hands_off(): void
    {
        $long = str_repeat('A', 800);
        $this->driver->enqueueText($long)->enqueueText($long)->enqueueText($long);

        $conversation = $this->conversation(AgentChannel::Sms);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'tell me everything',
        );

        $max = (int) config('agents.channel.sms.max_redraft_attempts');
        $this->assertSame(1 + $max, $this->driver->callCount);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::Error, $turn->handoff->reason);
        $this->assertSame('channel', $turn->blockedBy);
        $this->assertSame('sms_too_long', $turn->handoff->detail['reason'] ?? null);
    }

    #[Test]
    public function sms_over_ceiling_with_seven_class_paste_retries_then_hands_off(): void
    {
        $paste = $this->sevenClassAvailabilityAndQuotes();
        $this->assertGreaterThan(
            (int) config('agents.channel.sms.max_segments'),
            (new SmsMessage('x', Gsm7Transliterator::apply($paste)['body']))->segmentCount(),
        );

        $this->driver->enqueueText($paste)->enqueueText($paste)->enqueueText($paste);

        $conversation = $this->conversation(AgentChannel::Sms);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            $paste,
        );

        $max = (int) config('agents.channel.sms.max_redraft_attempts');
        $this->assertSame(1 + $max, $this->driver->callCount);
        $this->assertSame('channel', $turn->blockedBy);
        $this->assertSame(HandoffReason::Error, $turn->handoff?->reason);
    }

    #[Test]
    public function non_channel_block_on_redraft_ends_the_turn_with_that_guard(): void
    {
        $long = str_repeat('A', 800);
        $this->driver->enqueueText($long)->enqueueText('We held unit EV-001 for you.');

        $conversation = $this->conversation(AgentChannel::Sms);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'tell me everything',
        );

        $this->assertSame(2, $this->driver->callCount);
        $this->assertSame('grounding', $turn->blockedBy);
        $this->assertSame(HandoffReason::GroundingFailure, $turn->handoff?->reason);
    }

    #[Test]
    public function compact_seven_class_list_warns_and_sends(): void
    {
        $list = $this->compactSevenClassList();
        $translit = Gsm7Transliterator::apply($list)['body'];
        $this->assertSame(5, (new SmsMessage('x', $translit))->segmentCount());

        $this->driver->enqueueText($list);
        $events = [];
        $conversation = $this->conversation(AgentChannel::Sms);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            $list,
            $this->collectGuardrail($events),
        );

        $this->assertNull($turn->handoff);
        $this->assertNull($turn->blockedBy);
        $this->assertSame(1, $this->driver->callCount);

        $channel = $this->lastChannelEvent($events);
        $this->assertSame('warn', $channel['verdict'] ?? null);
        $this->assertSame(5, $channel['detail']['segments'] ?? null);
        $this->assertTrue($channel['detail']['gsm7_transliterated'] ?? false);
    }

    #[Test]
    public function spanish_accented_gsm7_characters_pass_through_unmodified(): void
    {
        $spanish = 'café, año. ¿qué tal? ¡hola!';
        $this->driver->enqueueText($spanish);
        $events = [];

        $conversation = $this->conversation(AgentChannel::Sms);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hola',
            $this->collectGuardrail($events),
        );

        $this->assertNull($turn->handoff);
        $this->assertStringContainsString($spanish, $turn->draft);

        $channel = $this->lastChannelEvent($events);
        $this->assertSame('gsm7', $channel['detail']['encoding'] ?? null);
        $this->assertArrayNotHasKey('gsm7_transliterated', $channel['detail'] ?? []);
    }

    #[Test]
    public function email_and_whatsapp_bodies_are_byte_identical(): void
    {
        $body = 'We measure in m² and bill in €.';

        $this->driver->enqueueText($body);
        $email = $this->conversation(AgentChannel::Email);
        $emailTurn = app(AgentRuntime::class)->turn($email, $email->principal(), 'please email me');
        $this->assertStringContainsString('m²', $emailTurn->draft);
        $this->assertStringContainsString('€', $emailTurn->draft);

        $this->driver->enqueueText($body);
        $events = [];
        $whatsapp = $this->conversation(AgentChannel::Whatsapp);
        $waTurn = app(AgentRuntime::class)->turn(
            $whatsapp,
            $whatsapp->principal(),
            'hello',
            $this->collectGuardrail($events),
        );
        $this->assertStringContainsString('m²', $waTurn->draft);
        $this->assertStringContainsString('€', $waTurn->draft);
        $channel = $this->lastChannelEvent($events);
        $this->assertArrayNotHasKey('gsm7_transliterated', $channel['detail'] ?? []);
        $this->assertArrayNotHasKey('segments', $channel['detail'] ?? []);
    }

    #[Test]
    public function gsm7_transliterated_is_absent_when_the_body_did_not_change(): void
    {
        $this->driver->enqueueText('Hello from Madrid Centro.');
        $events = [];
        $conversation = $this->conversation(AgentChannel::Sms);
        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hi',
            $this->collectGuardrail($events),
        );

        $channel = $this->lastChannelEvent($events);
        $this->assertArrayNotHasKey('gsm7_transliterated', $channel['detail'] ?? []);
        $this->assertArrayNotHasKey('original_body', $channel['detail'] ?? []);
    }

    #[Test]
    public function email_missing_subject_is_synthesized(): void
    {
        $this->driver->enqueueText('Here is the quote.');

        $conversation = $this->conversation(AgentChannel::Email);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'please email me a quote',
        );

        $this->assertSame(1, $this->driver->callCount);
        $this->assertNull($turn->handoff);
        $this->assertNull($turn->blockedBy);
        $this->assertSame('Here is the quote', $turn->subject);
        $this->assertStringContainsString('Here is the quote.', $turn->draft);
    }

    #[Test]
    public function email_subject_is_extracted_on_pass(): void
    {
        $this->driver->enqueueText("Subject: Availability\nWe have space this week.");

        $conversation = $this->conversation(AgentChannel::Email);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'any units free',
        );

        $this->assertNull($turn->handoff);
        $this->assertSame('Availability', $turn->subject);
        $this->assertStringNotContainsString('Subject:', $turn->draft);
        $this->assertStringContainsString('We have space this week.', $turn->draft);
    }

    #[Test]
    public function email_subject_uses_the_first_complete_clause(): void
    {
        $this->driver->enqueueText('We found several Keevaris sites in Madrid. Could you let me know which one is');

        $conversation = $this->conversation(AgentChannel::Email);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'I am in Madrid',
        );

        $this->assertNull($turn->handoff);
        $this->assertSame('We found several Keevaris sites in Madrid', $turn->subject);
        $this->assertLessThanOrEqual(70, mb_strlen((string) $turn->subject));
    }

    #[Test]
    public function email_subject_word_boundary_cut_appends_ellipsis(): void
    {
        $draft = 'Please confirm availability at Madrid Norte starting whenever you decide next week together with us';
        $this->assertGreaterThan(70, mb_strlen($draft));

        $verdict = $this->subjectOf($draft, 'en');
        $this->assertNotNull($verdict->subject);
        $this->assertLessThanOrEqual(71, mb_strlen($verdict->subject));
        $this->assertStringEndsWith('…', $verdict->subject);
        $this->assertStringNotContainsString(',', $verdict->subject);
    }

    #[Test]
    public function email_subject_drops_trailing_spanish_stopwords(): void
    {
        $verdict = $this->subjectOf('Encontramos varios centros Keevaris en Madrid que', 'es');

        $this->assertSame('Encontramos varios centros Keevaris en Madrid', $verdict->subject);
    }

    #[Test]
    public function email_subject_drops_trailing_french_stopwords(): void
    {
        $verdict = $this->subjectOf('Nous avons plusieurs sites Keevaris a Paris et', 'fr');

        $this->assertSame('Nous avons plusieurs sites Keevaris a Paris', $verdict->subject);
    }

    private function subjectOf(string $draft, string $locale): GuardrailVerdict
    {
        $conversation = $this->conversation(AgentChannel::Email);
        $conversation->locale = $locale;
        $conversation->save();

        return app(ChannelGuard::class)->check(
            $draft,
            new FactBag,
            new AgentContext(
                AgentPrincipal::anonymous(null, $locale),
                ChannelProfile::for(AgentChannel::Email),
                app(AgentRegistry::class)->get('support'),
                $conversation,
                $conversation->aiAgent,
            ),
        );
    }

    #[Test]
    public function voice_draft_over_the_character_ceiling_retries(): void
    {
        $draft = str_repeat('We have a few of that size left. ', 40);
        $this->assertGreaterThan(600, mb_strlen($draft));

        $conversation = $this->conversation(AgentChannel::Voice);
        $verdict = app(ChannelGuard::class)->check(
            $draft,
            new FactBag,
            new AgentContext(
                AgentPrincipal::anonymous(null, 'en'),
                ChannelProfile::for(AgentChannel::Voice),
                app(AgentRegistry::class)->get('concierge'),
                $conversation,
                $conversation->aiAgent,
            ),
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('channel', $verdict->blockedBy);
        $this->assertNotNull($verdict->retry);
        $this->assertSame('voice_too_long', $verdict->detail['reason'] ?? null);
    }

    #[Test]
    public function html_is_stripped_on_plain_text_channels(): void
    {
        $this->driver->enqueueText('<b>Hi</b> there.');

        $conversation = $this->conversation(AgentChannel::Sms);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hi',
        );

        $this->assertNull($turn->handoff);
        $this->assertStringNotContainsString('<b>', $turn->draft);
        $this->assertStringContainsString('Hi there.', $turn->draft);
    }

    #[Test]
    public function whatsapp_emits_an_advisory_guardrail_event(): void
    {
        $this->driver->enqueueText('Hello from WhatsApp.');
        $events = [];

        $conversation = $this->conversation(AgentChannel::Whatsapp);
        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hello',
            $this->collectGuardrail($events),
        );

        $channelEvents = array_values(array_filter(
            $events,
            fn (array $event): bool => ($event['guard'] ?? null) === 'channel',
        ));
        $this->assertNotEmpty($channelEvents);
        $this->assertTrue($channelEvents[0]['detail']['advisory'] ?? false);
        $this->assertSame('template', $channelEvents[0]['detail']['outside_window_mode'] ?? null);
        $this->assertSame('session', $channelEvents[0]['detail']['inside_window_mode'] ?? null);
    }

    /**
     * @return Closure(string, array<string, mixed>): void
     */
    private function collectGuardrail(array &$events): \Closure
    {
        return function (string $type, array $payload) use (&$events): void {
            if ($type === 'guardrail') {
                $events[] = $payload;
            }
        };
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function lastChannelEvent(array $events): array
    {
        $channelEvents = array_values(array_filter(
            $events,
            fn (array $event): bool => ($event['guard'] ?? null) === 'channel',
        ));
        $this->assertNotEmpty($channelEvents);

        return $channelEvents[array_key_last($channelEvents)];
    }

    private function lastAssistantMessage(AgentConversation $conversation): ?AgentConversationMessage
    {
        return $conversation->messages()
            ->where('role', AgentMessageRole::Assistant)
            ->whereNull('tool_calls')
            ->orderByDesc('sequence')
            ->first();
    }

    private function ucs2SevenSegmentDraft(): string
    {
        $unit = 'Storage in m² — billed in €. ';
        $body = $unit;
        while ((new SmsMessage('x', $body))->segmentCount() < 7) {
            $body .= $unit;
        }

        return $body;
    }

    private function compactSevenClassList(): string
    {
        $list = 'Madrid Centro availability: '
            .'Trastero 5 m²: 3 available, €72.00 net / €87.12 incl. 21% IVA per month. '
            .'Trastero 8 m²: 2 available, €104.00 net / €125.84 incl. 21% IVA per month. '
            .'Trastero 10 m²: 4 available, €128.00 net / €154.88 incl. 21% IVA per month. '
            .'Trastero 11 m²: 1 available, €140.00 net / €169.40 incl. 21% IVA per month. '
            .'Trastero 12 m²: 2 available, €152.00 net / €183.92 incl. 21% IVA per month. '
            .'Trastero 12 m² XL: 3 available, €168.00 net / €203.28 incl. 21% IVA per month. '
            .'Trastero 14 m² XL: 2 available, €192.00 net / €232.32 incl. 21% IVA per month.';

        $filler = ' Ask if you want hours or a viewing.';
        while ((new SmsMessage('x', Gsm7Transliterator::apply($list)['body']))->segmentCount() < 5) {
            $list .= $filler;
        }

        return $list;
    }

    private function sevenClassAvailabilityAndQuotes(): string
    {
        $classes = [
            ['Trastero 5 m²', '5.00', '72.00', '87.12', 3],
            ['Trastero 8 m²', '8.00', '104.00', '125.84', 2],
            ['Trastero 10 m²', '10.00', '128.00', '154.88', 4],
            ['Trastero 11 m²', '11.00', '140.00', '169.40', 1],
            ['Trastero 12 m²', '12.00', '152.00', '183.92', 2],
            ['Trastero 12 m² XL', '12.00', '168.00', '203.28', 3],
            ['Trastero 14 m² XL', '14.00', '192.00', '232.32', 2],
        ];

        $avail = [];
        $quotes = [];
        foreach ($classes as [$label, $size, $net, $gross, $n]) {
            $unit = $n === 1 ? 'unit' : 'units';
            $avail[] = "{$n} {$unit} available in {$label} ({$size} m²) at Madrid Centro as of now.";
            $quotes[] = "€{$net} net / €{$gross} incl. 21% IVA, per month — {$label} at Madrid Centro";
        }

        return implode(' ', $avail).' '.implode(' ', $quotes);
    }

    private function conversation(AgentChannel $channel): AgentConversation
    {
        $agent = AiAgent::query()->firstOrCreate(
            ['key' => 'support'],
            [
                'name' => 'support',
                'is_active' => true,
                'model' => config('agents.default_model', 'claude-sonnet-4-6'),
            ],
        );

        return AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => $channel,
            'locale' => 'en',
        ]);
    }
}
