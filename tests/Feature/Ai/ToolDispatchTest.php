<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\SpyTool;
use Tests\Support\Ai\TestAgentDefinition;
use Tests\TestCase;

class ToolDispatchTest extends TestCase
{
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
}
