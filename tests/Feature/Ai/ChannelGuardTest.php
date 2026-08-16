<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\HandoffReason;
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
    public function sms_over_cap_retries_once_then_hands_off(): void
    {
        $long = str_repeat('A', 1601);
        $this->driver->enqueueText($long)->enqueueText($long);

        $conversation = $this->conversation(AgentChannel::Sms);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'tell me everything',
        );

        $this->assertSame(2, $this->driver->callCount);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::Error, $turn->handoff->reason);
        $this->assertSame('channel', $turn->blockedBy);
    }

    #[Test]
    public function email_missing_subject_retries_once_then_hands_off(): void
    {
        $this->driver->enqueueText('Here is the quote.')->enqueueText('Here is the quote.');

        $conversation = $this->conversation(AgentChannel::Email);
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'please email me a quote',
        );

        $this->assertSame(2, $this->driver->callCount);
        $this->assertSame(HandoffReason::Error, $turn->handoff?->reason);
        $this->assertSame('channel', $turn->blockedBy);
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
            function (string $type, array $payload) use (&$events): void {
                if ($type === 'guardrail') {
                    $events[] = $payload;
                }
            },
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

    private function conversation(AgentChannel $channel): AgentConversation
    {
        $agent = AiAgent::factory()->create([
            'key' => 'support',
            'name' => 'support',
            'is_active' => true,
        ]);

        return AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => $channel,
            'locale' => 'en',
        ]);
    }
}
