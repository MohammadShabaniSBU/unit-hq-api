<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\CrmCopilotAgent;
use App\Ai\Middleware\MetersUsage;
use App\Console\Commands\SweepAiUsageCommand;
use App\Listeners\RecordAgentFailoverUsage;
use App\Listeners\RecordAgentUsage;
use App\Models\AiUsageEvent;
use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function successful_turn_settles_row(): void
    {
        $employee = Employee::factory()->manager()->create();
        $callId = (string) Str::uuid7();

        Context::add([
            'ai_call_id' => $callId,
            'employee_id' => $employee->id,
            'ai_purpose' => 'copilot',
            'conversation_id' => (string) Str::uuid7(),
        ]);

        $this->runMetersUsage();

        $usage = new Usage(120, 40, 0, 15, 0);
        $response = new AgentResponse(
            $callId,
            'Done.',
            $usage,
            new Meta('anthropic', 'claude-sonnet-5'),
        );

        (new RecordAgentUsage)->handle($this->makePromptedEvent($response));

        $row = AiUsageEvent::query()->where('call_id', $callId)->first();
        $this->assertNotNull($row);
        $this->assertSame(AiUsageEvent::STATUS_OK, $row->status);
        $this->assertSame($employee->id, $row->employee_id);
        $this->assertSame(120, $row->input_tokens);
        $this->assertSame(40, $row->output_tokens);
        $this->assertSame(15, $row->cached_input_tokens);
        $this->assertSame('anthropic', $row->provider);
        $this->assertSame('claude-sonnet-5', $row->model);
        $this->assertNotNull($row->settled_at);
    }

    #[Test]
    public function failed_turn_settles_terminal_row(): void
    {
        $callId = (string) Str::uuid7();
        AiUsageEvent::reserve($callId, Employee::factory()->manager()->create()->id);

        AiUsageEvent::markFailed($callId);

        $row = AiUsageEvent::query()->where('call_id', $callId)->first();
        $this->assertNotNull($row);
        $this->assertSame(AiUsageEvent::STATUS_FAILED, $row->status);
        $this->assertNotNull($row->settled_at);
    }

    #[Test]
    public function worker_timeout_leaves_orphan(): void
    {
        $callId = (string) Str::uuid7();
        $row = AiUsageEvent::reserve($callId, Employee::factory()->manager()->create()->id);
        $this->assertNotNull($row);

        $row->forceFill(['started_at' => now()->subMinutes(45)])->save();

        Artisan::call(SweepAiUsageCommand::class);

        $row->refresh();
        $this->assertSame(AiUsageEvent::STATUS_ORPHANED, $row->status);
        $this->assertNotNull($row->settled_at);
    }

    #[Test]
    public function failover_records_both_legs(): void
    {
        $employee = Employee::factory()->manager()->create();
        $firstCallId = (string) Str::uuid7();

        Context::add([
            'ai_call_id' => $firstCallId,
            'employee_id' => $employee->id,
            'ai_purpose' => 'copilot',
        ]);

        AiUsageEvent::reserve($firstCallId, $employee->id);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('anthropic');

        (new RecordAgentFailoverUsage)->handle(new AgentFailedOver(
            new CrmCopilotAgent($employee),
            $provider,
            'claude-sonnet-5',
            RateLimitedException::forProvider('anthropic', 429),
        ));

        $first = AiUsageEvent::query()->where('call_id', $firstCallId)->first();
        $this->assertNotNull($first);
        $this->assertSame(AiUsageEvent::STATUS_FAILED_OVER, $first->status);

        $secondCallId = Context::get('ai_call_id');
        $this->assertIsString($secondCallId);
        $this->assertNotSame($firstCallId, $secondCallId);

        AiUsageEvent::reserve($secondCallId, $employee->id);
        AiUsageEvent::settle(
            $secondCallId,
            new Usage(10, 5),
            AiUsageEvent::STATUS_OK,
            'openai',
            'gpt-4.1',
        );

        $second = AiUsageEvent::query()->where('call_id', $secondCallId)->first();
        $this->assertNotNull($second);
        $this->assertSame(AiUsageEvent::STATUS_OK, $second->status);
        $this->assertSame(2, AiUsageEvent::query()->count());
    }

    #[Test]
    public function approval_pause_shares_conversation_id(): void
    {
        $employee = Employee::factory()->manager()->create();
        $conversationId = (string) Str::uuid7();
        $first = (string) Str::uuid7();
        $second = (string) Str::uuid7();

        AiUsageEvent::reserve($first, $employee->id, $conversationId);
        AiUsageEvent::settle($first, new Usage(20, 10), AiUsageEvent::STATUS_OK, 'anthropic', 'claude-sonnet-5');

        AiUsageEvent::reserve($second, $employee->id, $conversationId);
        AiUsageEvent::settle($second, new Usage(30, 15), AiUsageEvent::STATUS_OK, 'anthropic', 'claude-sonnet-5');

        $rows = AiUsageEvent::query()->where('conversation_id', $conversationId)->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (AiUsageEvent $e) => $e->conversation_id === $conversationId));
    }

    #[Test]
    public function raw_usage_persisted(): void
    {
        $callId = (string) Str::uuid7();
        AiUsageEvent::reserve($callId, Employee::factory()->manager()->create()->id);

        $usage = new Usage(11, 22, 3, 4, 5);
        AiUsageEvent::settle($callId, $usage, AiUsageEvent::STATUS_OK, 'anthropic', 'claude-sonnet-5', $usage);

        $row = AiUsageEvent::query()->where('call_id', $callId)->first();
        $this->assertNotNull($row);
        $this->assertSame([
            'prompt_tokens' => 11,
            'completion_tokens' => 22,
            'cache_write_input_tokens' => 3,
            'cache_read_input_tokens' => 4,
            'reasoning_tokens' => 5,
        ], $row->raw_usage);
    }

    #[Test]
    public function attribution_survives_queue_boundary(): void
    {
        $employee = Employee::factory()->manager()->create();
        $callId = (string) Str::uuid7();

        // Simulate worker Context hydrated from the queued job payload — no auth session.
        Context::add([
            'ai_call_id' => $callId,
            'employee_id' => $employee->id,
            'ai_purpose' => 'copilot',
            'conversation_id' => (string) Str::uuid7(),
        ]);

        $this->runMetersUsage();

        $row = AiUsageEvent::query()->where('call_id', $callId)->first();
        $this->assertNotNull($row);
        $this->assertSame($employee->id, $row->employee_id);
        $this->assertSame('copilot', $row->purpose);
    }

    private function runMetersUsage(): void
    {
        $middleware = new MetersUsage;
        $prompt = Mockery::mock(AgentPrompt::class);
        $middleware->handle($prompt, fn ($p) => 'ok');
    }

    private function makePromptedEvent(AgentResponse $response): AgentPrompted
    {
        $prompt = Mockery::mock(AgentPrompt::class);

        return new AgentPrompted($response->invocationId, $prompt, $response);
    }
}
