<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentToolInvocation;
use App\Models\AgentWritePolicy;
use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\Ai\SpyTool;
use Tests\Support\Ai\TestAgentDefinition;
use Tests\TestCase;

class AgentQuotaTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    private ToolDispatcher $dispatcher;

    private SpyTool $write;

    private TestAgentDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->write = new SpyTool(
            key: 'test.write',
            required: VerificationLevel::Anonymous,
            contactKeys: [],
            throwOnHandle: false,
            write: true,
            schema: [
                'n' => [
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Discriminator',
                ],
            ],
        );
        $this->definition = new TestAgentDefinition('test-write', ['test.write']);

        app(ToolRegistry::class)->register($this->write);
        app(AgentRegistry::class)->register($this->definition);
        $this->dispatcher = app(ToolDispatcher::class);
    }

    #[Test]
    public function max_per_conversation_counts_only_ok_rows(): void
    {
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'test-write');
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'test.write',
            'max_per_conversation' => 1,
        ]);
        $ctx->agent->load('writePolicies');

        AgentToolInvocation::query()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'tool_key' => 'test.write',
            'arguments' => ['n' => 0],
            'status' => ToolInvocationStatus::Denied,
            'denied_reason' => ToolDeniedReason::Verification,
            'principal_verification' => $principal->verification,
        ]);

        $ok = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 1], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $ok->status);
        $this->assertTrue($this->write->handleCalled);

        $this->recordInvocation($ctx, 'test.write', ['n' => 1], $ok, $principal);
        $this->write->handleCalled = false;

        $denied = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 2], $ctx);
        $this->assertSame(ToolInvocationStatus::Denied, $denied->status);
        $this->assertSame(ToolDeniedReason::QuotaExceeded, $denied->deniedReason);
        $this->assertFalse($this->write->handleCalled);
    }

    #[Test]
    public function a_denied_call_does_not_consume_quota(): void
    {
        $write = new SpyTool(
            key: 'test.gated',
            required: VerificationLevel::Verified,
            contactKeys: [],
            throwOnHandle: false,
            write: true,
            schema: [
                'n' => [
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Discriminator',
                ],
            ],
        );
        $definition = new TestAgentDefinition('test-gated', ['test.gated']);
        app(ToolRegistry::class)->register($write);
        app(AgentRegistry::class)->register($definition);

        $ctx = $this->writeContext(AgentPrincipal::verified(Contact::factory()->create()->id, null, 'en'), 'test-gated');
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'test.gated',
            'max_per_conversation' => 1,
        ]);
        $ctx->agent->load('writePolicies');

        $denied = app(ToolDispatcher::class)->dispatch(
            $definition,
            AgentPrincipal::anonymous(null, 'en'),
            'test.gated',
            ['n' => 1],
            $ctx,
        );
        $this->assertSame(ToolDeniedReason::Verification, $denied->deniedReason);
        $this->assertFalse($write->handleCalled);

        $ok = app(ToolDispatcher::class)->dispatch(
            $definition,
            $ctx->principal,
            'test.gated',
            ['n' => 1],
            $ctx,
        );
        $this->assertSame(ToolInvocationStatus::Ok, $ok->status);
        $this->assertTrue($write->handleCalled);
    }

    #[Test]
    public function max_per_day_rolls_at_app_timezone_midnight(): void
    {
        Carbon::setTestNow('2026-08-24 23:30:00');

        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'test-write', origin: AgentOrigin::Webchat);
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'test.write',
            'max_per_day' => 1,
        ]);
        $ctx->agent->load('writePolicies');

        $first = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 1], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $first->status);
        $this->recordInvocation($ctx, 'test.write', ['n' => 1], $first, $principal);
        $this->write->handleCalled = false;

        $blocked = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 2], $ctx);
        $this->assertSame(ToolDeniedReason::QuotaExceeded, $blocked->deniedReason);
        $this->assertFalse($this->write->handleCalled);

        Carbon::setTestNow('2026-08-25 00:01:00');
        $this->write->handleCalled = false;

        $rolled = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 2], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $rolled->status);
        $this->assertTrue($this->write->handleCalled);
    }

    #[Test]
    public function demo_origin_does_not_consume_max_per_day_and_does_consume_max_per_conversation(): void
    {
        $principal = AgentPrincipal::anonymous(null, 'en');
        $demo = $this->writeContext($principal, 'test-write', origin: AgentOrigin::Demo);
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $demo->agent->id,
            'tool_key' => 'test.write',
            'max_per_conversation' => 1,
            'max_per_day' => 1,
        ]);
        $demo->agent->load('writePolicies');

        $demoWrite = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 1], $demo);
        $this->assertSame(ToolInvocationStatus::Ok, $demoWrite->status);
        $this->recordInvocation($demo, 'test.write', ['n' => 1], $demoWrite, $principal);
        $this->write->handleCalled = false;

        $conversationCap = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 2], $demo);
        $this->assertSame(ToolDeniedReason::QuotaExceeded, $conversationCap->deniedReason);
        $this->assertFalse($this->write->handleCalled);

        $liveConversation = AgentConversation::factory()->create([
            'ai_agent_id' => $demo->agent->id,
            'audience' => $principal->audience,
            'origin' => AgentOrigin::Webchat,
            'channel' => $demo->conversation->channel,
            'employee_id' => null,
            'created_by_employee_id' => $demo->conversation->created_by_employee_id,
            'contact_id' => $principal->contactId,
            'site_id' => $principal->siteId,
            'verification_level' => $principal->verification,
            'state' => $demo->conversation->state,
            'locale' => $principal->locale,
        ]);

        $live = new AgentContext(
            $principal,
            $demo->channel,
            $demo->definition,
            $liveConversation,
            $demo->agent,
        );

        $this->write->handleCalled = false;
        $liveWrite = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 1], $live);
        $this->assertSame(ToolInvocationStatus::Ok, $liveWrite->status);
        $this->assertTrue($this->write->handleCalled);

        $this->recordInvocation($live, 'test.write', ['n' => 1], $liveWrite, $principal);
        $this->write->handleCalled = false;

        $dayCap = $this->dispatcher->dispatch($this->definition, $principal, 'test.write', ['n' => 2], $live);
        $this->assertSame(ToolDeniedReason::QuotaExceeded, $dayCap->deniedReason);
        $this->assertFalse($this->write->handleCalled);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
