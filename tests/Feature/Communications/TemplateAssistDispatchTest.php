<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Ai\Agents\TemplateAssistantAgent;
use App\Ai\Middleware\MetersUsage;
use App\Enums\TemplateAssistTurnStatus;
use App\Enums\TemplateChannel;
use App\Enums\TemplateVersionStatus;
use App\Events\TemplateAssistTurnFinished;
use App\Models\AiUsageEvent;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\SystemEvent;
use App\Models\TemplateAssistSession;
use App\Models\TemplateAssistTurn;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Templates\Assist\CanonicalDocument;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TemplateAssistDispatchTest extends TestCase
{
    use InteractsWithTemplateAssist;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
    }

    #[Test]
    public function idempotent_client_message(): void
    {
        Queue::fake();
        $this->assistManager();
        [$family, $version] = $this->assistDraft();
        $sessionId = $this->openAssistSession($family);

        $payload = [
            'client_message_id' => 'msg-once',
            'message' => 'Soften the tone',
            'template_version_id' => $version->id,
            'document' => $this->assistDocument(),
            'base_hash' => 'client-supplied',
        ];

        $first = $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", $payload);
        $first->assertAccepted()
            ->assertJsonPath('data.session_id', $sessionId)
            ->assertJsonPath('data.channel', "private-template-assist.{$sessionId}")
            ->assertJsonStructure(['data' => ['call_id', 'turn_id', 'session_id', 'channel']]);

        $second = $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", $payload);
        $second->assertAccepted()
            ->assertJsonPath('data.call_id', $first->json('data.call_id'))
            ->assertJsonPath('data.turn_id', $first->json('data.turn_id'));

        Queue::assertPushedOn('ai', BroadcastAgent::class);
        Queue::assertPushed(BroadcastAgent::class, 1);
        $this->assertSame(1, TemplateAssistTurn::query()->count());

        $turn = TemplateAssistTurn::query()->firstOrFail();
        $expected = CanonicalDocument::hash(EmailBlockDocument::validateForWrite($this->assistDocument()));
        $this->assertSame($expected, $turn->base_hash);
        $this->assertNotSame('client-supplied', $turn->base_hash);
        $this->assertSame(64, strlen($turn->base_hash));
    }

    #[Test]
    public function stale_draft_conflict(): void
    {
        Queue::fake();
        $this->assistManager();
        [$family, $draft] = $this->assistDraft();

        $published = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 2,
            'status' => TemplateVersionStatus::Published,
            'published_at' => now(),
        ]);

        $sessionId = $this->openAssistSession($family);

        $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'msg-stale',
            'message' => 'Soften the tone',
            'template_version_id' => $published->id,
            'document' => $this->assistDocument(),
        ])->assertStatus(409)
            ->assertJsonPath('message', 'assist.draft_changed');

        $other = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'Other',
        ]);
        $foreign = TemplateVersion::query()->create([
            'template_family_id' => $other->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);

        $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'msg-foreign',
            'message' => 'Soften the tone',
            'template_version_id' => $foreign->id,
            'document' => $this->assistDocument(),
        ])->assertNotFound();

        Queue::assertNotPushed(BroadcastAgent::class);
        $this->assertSame($draft->id, $family->draft()->firstOrFail()->id);
    }

    #[Test]
    public function daily_limit(): void
    {
        Queue::fake();
        $employee = $this->assistManager();
        [$family, $version] = $this->assistDraft();
        $sessionId = $this->openAssistSession($family);
        $session = TemplateAssistSession::query()->findOrFail($sessionId);

        for ($i = 0; $i < 100; $i++) {
            $this->insertTurn($session, $version, 'seed-'.$i);
        }

        $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'msg-101',
            'message' => 'One more',
            'template_version_id' => $version->id,
            'document' => $this->assistDocument(),
        ])->assertStatus(429)
            ->assertJsonPath('message', 'assist.daily_limit_reached');

        $this->assertSame(100, TemplateAssistTurn::query()->count());

        $replay = $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'seed-0',
            'message' => 'Again',
            'template_version_id' => $version->id,
            'document' => $this->assistDocument(),
        ]);
        $replay->assertAccepted()
            ->assertJsonPath('data.call_id', TemplateAssistTurn::query()->where('client_message_id', 'seed-0')->value('call_id'));

        $this->assertSame(100, TemplateAssistTurn::query()->count());
        $this->assertSame($employee->id, $session->employee_id);
        Queue::assertNotPushed(BroadcastAgent::class);
    }

    #[Test]
    public function rejects_non_email_family(): void
    {
        $this->assistManager();
        $family = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Sms,
            'name' => 'Text notice',
        ]);

        $this->postJson("/api/template-families/{$family->id}/assist/sessions", [
            'locale' => 'en',
        ])->assertStatus(422)
            ->assertJsonPath('errors.channel.0', 'assist.channel_unsupported');
    }

    #[Test]
    public function usage_purpose_template_assist(): void
    {
        Queue::fake();
        $this->assistManager();
        [$family, $version] = $this->assistDraft();
        $sessionId = $this->openAssistSession($family);

        $captured = null;
        Event::listen('eloquent.updated: '.TemplateAssistTurn::class, function (TemplateAssistTurn $turn) use (&$captured): void {
            $purpose = Context::get('ai_purpose');
            if (! is_string($purpose)) {
                return;
            }

            $captured = [
                'ai_call_id' => Context::get('ai_call_id'),
                'employee_id' => Context::get('employee_id'),
                'ai_purpose' => $purpose,
                'conversation_id' => Context::get('conversation_id'),
            ];
        });

        $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'msg-usage',
            'message' => 'Soften the tone',
            'template_version_id' => $version->id,
            'document' => $this->assistDocument(),
        ])->assertAccepted();

        $this->assertIsArray($captured);
        $this->assertSame('template_assist', $captured['ai_purpose']);

        Context::flush();
        Context::add($captured);

        $middleware = new MetersUsage;
        $middleware->handle(Mockery::mock(AgentPrompt::class), fn (AgentPrompt $prompt): string => 'ok');

        $row = AiUsageEvent::query()->where('call_id', $captured['ai_call_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame('template_assist', $row->purpose);
    }

    #[Test]
    public function failed_provider_call_marks_turn_and_usage(): void
    {
        Context::flush();

        $employee = Employee::factory()->manager()->create();
        [$family, $version] = $this->assistDraft();
        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Rate notice · en',
            'site_scope_snapshot' => null,
        ]);
        $session = TemplateAssistSession::query()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'conversation_id' => $conversation->id,
            'employee_id' => $employee->id,
        ]);
        $turn = $this->insertTurn($session, $version, 'msg-fail');

        $agent = (new TemplateAssistantAgent($employee, $turn->id))
            ->continue($conversation->id, as: $employee);
        $broadcastJob = new BroadcastAgent(
            $agent,
            'This will fail',
            new PrivateChannel("template-assist.{$session->id}"),
        );

        AiUsageEvent::reserve(
            $broadcastJob->invocationId,
            $employee->id,
            $conversation->id,
            'template_assist',
        );

        $finished = null;
        Event::listen(TemplateAssistTurnFinished::class, function (TemplateAssistTurnFinished $event) use (&$finished): void {
            $finished = $event;
        });

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('payload')->andReturn([
            'data' => [
                'command' => serialize($broadcastJob),
            ],
        ]);

        event(new JobFailed('database', $queueJob, new RuntimeException('Provider exploded')));

        $turn->refresh();
        $this->assertSame(TemplateAssistTurnStatus::Failed, $turn->status);
        $this->assertSame('assist.provider_error', $turn->error_key);

        $usage = AiUsageEvent::query()->where('call_id', $broadcastJob->invocationId)->first();
        $this->assertNotNull($usage);
        $this->assertSame(AiUsageEvent::STATUS_FAILED, $usage->status);

        $system = SystemEvent::query()->where('event', 'template.assist.failed')->first();
        $this->assertNotNull($system);
        $this->assertSame($turn->id, $system->payload['turn_id']);
        $this->assertSame('assist.provider_error', $system->payload['error_key']);
        $this->assertArrayHasKey('model', $system->payload);

        $this->assertInstanceOf(TemplateAssistTurnFinished::class, $finished);
        $this->assertSame('template-assist.turn.finished', $finished->broadcastAs());
        $this->assertSame($turn->id, $finished->broadcastWith()['turn_id']);
        $this->assertSame('failed', $finished->broadcastWith()['status']);
    }

    private function insertTurn(
        TemplateAssistSession $session,
        TemplateVersion $version,
        string $clientMessageId,
    ): TemplateAssistTurn {
        $document = $this->assistDocument();

        return TemplateAssistTurn::query()->create([
            'session_id' => $session->id,
            'call_id' => (string) Str::uuid7(),
            'client_message_id' => $clientMessageId,
            'template_version_id' => $version->id,
            'base_document' => $document,
            'base_hash' => hash('sha256', $clientMessageId),
            'working_document' => $document,
            'ops' => [],
            'op_summary' => [],
            'prompt_version' => 'ta-1',
            'status' => TemplateAssistTurnStatus::Running,
        ]);
    }
}
