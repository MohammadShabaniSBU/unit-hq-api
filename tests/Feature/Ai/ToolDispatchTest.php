<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentWritePolicy;
use App\Models\Contact;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\Ai\SpyTool;
use Tests\Support\Ai\TestAgentDefinition;
use Tests\TestCase;

class ToolDispatchTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    private ToolDispatcher $dispatcher;

    private SpyTool $spy;

    private TestAgentDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spy = new SpyTool(throwOnHandle: true);
        $this->definition = new TestAgentDefinition('test', ['test.spy']);

        $registry = app(ToolRegistry::class);
        $registry->register($this->spy);
        app(AgentRegistry::class)->register($this->definition);

        $this->dispatcher = app(ToolDispatcher::class);
    }

    #[Test]
    public function denies_tool_not_allowed_for_agent_before_handle(): void
    {
        $result = $this->dispatcher->dispatch(
            new TestAgentDefinition('test', ['agent.escalate']),
            AgentPrincipal::verified(1, null, 'en'),
            'test.spy',
            ['contact_id' => 1],
        );

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::NotAllowedForAgent, $result->deniedReason);
        $this->assertFalse($this->spy->handleCalled);
    }

    #[Test]
    public function denies_insufficient_verification_before_handle(): void
    {
        $result = $this->dispatcher->dispatch(
            $this->definition,
            AgentPrincipal::anonymous(null, 'en'),
            'test.spy',
            ['contact_id' => 1],
        );

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::Verification, $result->deniedReason);
        $this->assertFalse($this->spy->handleCalled);
    }

    #[Test]
    public function returns_error_for_invalid_arguments_before_handle(): void
    {
        $wrongType = $this->dispatcher->dispatch(
            $this->definition,
            AgentPrincipal::verified(1, null, 'en'),
            'test.spy',
            ['contact_id' => 'not-an-id'],
        );

        $this->assertSame(ToolInvocationStatus::Error, $wrongType->status);
        $this->assertFalse($this->spy->handleCalled);
    }

    #[Test]
    public function denies_ownership_before_handle_including_missing_id(): void
    {
        $foreign = $this->dispatcher->dispatch(
            $this->definition,
            AgentPrincipal::verified(1, null, 'en'),
            'test.spy',
            ['contact_id' => 99],
        );

        $this->assertSame(ToolInvocationStatus::Denied, $foreign->status);
        $this->assertSame(ToolDeniedReason::Ownership, $foreign->deniedReason);
        $this->assertFalse($this->spy->handleCalled);

        $other = new SpyTool(key: 'test.own', required: VerificationLevel::Anonymous, throwOnHandle: true);
        app(ToolRegistry::class)->register($other);
        $def = new TestAgentDefinition('test-own', ['test.own']);

        $missing = $this->dispatcher->dispatch(
            $def,
            AgentPrincipal::verified(1, null, 'en'),
            'test.own',
            ['contact_id' => null],
        );

        $this->assertSame(ToolInvocationStatus::Denied, $missing->status);
        $this->assertSame(ToolDeniedReason::Ownership, $missing->deniedReason);
        $this->assertFalse($other->handleCalled);
    }

    #[Test]
    public function ok_path_reaches_handle(): void
    {
        $ok = new SpyTool(key: 'test.ok', required: VerificationLevel::Anonymous, throwOnHandle: false);
        app(ToolRegistry::class)->register($ok);
        $definition = new TestAgentDefinition('test-ok', ['test.ok']);

        $result = $this->dispatcher->dispatch(
            $definition,
            AgentPrincipal::verified(1, null, 'en'),
            'test.ok',
            ['contact_id' => 1],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertTrue($ok->handleCalled);
    }

    #[Test]
    public function absent_policy_is_commit_unlimited(): void
    {
        $ok = new SpyTool(key: 'test.ok', required: VerificationLevel::Anonymous, throwOnHandle: false, write: true);
        app(ToolRegistry::class)->register($ok);
        $definition = new TestAgentDefinition('test-ok', ['test.ok']);
        app(AgentRegistry::class)->register($definition);
        $contact = Contact::factory()->create();
        $ctx = $this->writeContext(AgentPrincipal::verified($contact->id, null, 'en'), 'test-ok');

        $result = $this->dispatcher->dispatch(
            $definition,
            AgentPrincipal::verified($contact->id, null, 'en'),
            'test.ok',
            ['contact_id' => $contact->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertTrue($ok->handleCalled);
        $this->assertNotNull($result->idempotencyKey);
    }

    #[Test]
    public function mode_off_denies_before_handle_without_database_touch(): void
    {
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::verified($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'test');
        AgentWritePolicy::factory()->off()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'test.spy',
        ]);
        $ctx->agent->load('writePolicies');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->dispatcher->dispatch(
            $this->definition,
            $principal,
            'test.spy',
            ['contact_id' => $contact->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::NotAllowedForAgent, $result->deniedReason);
        $this->assertFalse($this->spy->handleCalled);
        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function mode_propose_denies_requires_approval_before_handle(): void
    {
        $contact = Contact::factory()->create();
        $site = \App\Models\Site::factory()->create();
        $spy = new \Tests\Support\Ai\ProposableSpyTool(siteId: $site->id);
        app(ToolRegistry::class)->register($spy);
        $definition = new TestAgentDefinition('test-propose', ['test.spy']);
        app(AgentRegistry::class)->register($definition);

        $principal = AgentPrincipal::verified($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'test-propose');
        AgentWritePolicy::factory()->propose()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'test.spy',
        ]);
        $ctx->agent->load('writePolicies');

        $result = $this->dispatcher->dispatch(
            $definition,
            $principal,
            'test.spy',
            ['contact_id' => $contact->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::RequiresApproval, $result->deniedReason);
        $this->assertTrue($spy->proposeCalled);
        $this->assertFalse($spy->handleCalled);
        $this->assertSame(CannedReply::pendingApproval('en'), $result->display);
    }

    #[Test]
    public function raised_verification_denies_an_otherwise_sufficient_principal(): void
    {
        $spy = new SpyTool(
            key: 'test.anon',
            required: VerificationLevel::Anonymous,
            throwOnHandle: true,
        );
        app(ToolRegistry::class)->register($spy);
        $definition = new TestAgentDefinition('test-anon', ['test.anon']);
        app(AgentRegistry::class)->register($definition);

        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'test-anon');
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'test.anon',
            'min_verification' => VerificationLevel::Verified,
        ]);
        $ctx->agent->load('writePolicies');

        $result = $this->dispatcher->dispatch(
            $definition,
            AgentPrincipal::anonymous(null, 'en'),
            'test.anon',
            ['contact_id' => 1],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::Verification, $result->deniedReason);
        $this->assertFalse($spy->handleCalled);
    }
}
