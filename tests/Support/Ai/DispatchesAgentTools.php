<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AgentToolInvocation;
use App\Models\AiAgent;
use App\Models\Employee;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\PendingActionRecorder;
use App\Support\Ai\Tools\ArgumentBag;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolResult;

trait DispatchesAgentTools
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function dispatchTool(
        string $agentKey,
        string $toolKey,
        AgentPrincipal $principal,
        array $arguments = [],
        ?AgentContext $ctx = null,
    ): ToolResult {
        $definition = app(AgentRegistry::class)->get($agentKey);

        return app(ToolDispatcher::class)->dispatch($definition, $principal, $toolKey, $arguments, $ctx);
    }

    protected function writeContext(
        AgentPrincipal $principal,
        string $agentKey,
        ?Employee $employee = null,
        AgentOrigin $origin = AgentOrigin::Demo,
    ): AgentContext {
        $employee ??= Employee::factory()->create();
        $agent = AiAgent::factory()->create([
            'key' => $agentKey.'-'.uniqid(),
            'name' => $agentKey,
        ]);

        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'audience' => $principal->audience,
            'origin' => $origin,
            'channel' => AgentChannel::Webchat,
            'employee_id' => $principal->audience === AgentAudience::Internal ? $principal->employeeId : null,
            'created_by_employee_id' => $employee->id,
            'contact_id' => $principal->contactId,
            'site_id' => $principal->siteId,
            'verification_level' => $principal->verification,
            'state' => ConversationState::Active,
            'locale' => $principal->locale,
        ]);

        $agent->load('writePolicies');

        return new AgentContext(
            $principal,
            ChannelProfile::for(AgentChannel::Webchat),
            app(AgentRegistry::class)->get($agentKey),
            $conversation,
            $agent,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function recordInvocation(
        AgentContext $ctx,
        string $toolKey,
        array $arguments,
        ToolResult $result,
        AgentPrincipal $principal,
    ): AgentToolInvocation {
        $factKeys = $result->facts->all();

        $invocation = AgentToolInvocation::query()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'tool_key' => $toolKey,
            'arguments' => ArgumentBag::normalise($arguments),
            'result' => $result->toTraceResult(),
            'result_summary' => $result->display,
            'status' => $result->status,
            'denied_reason' => $result->deniedReason,
            'required_verification' => null,
            'principal_verification' => $principal->verification,
            'idempotency_key' => $result->idempotencyKey,
            'result_type' => $result->resultType,
            'result_id' => $result->resultId,
            'fact_keys' => $factKeys !== [] ? $factKeys : null,
        ]);

        if ($result->deniedReason === ToolDeniedReason::RequiresApproval) {
            $invocation->loadMissing('conversation');
            app(PendingActionRecorder::class)->record($invocation, $result);
        }

        return $invocation;
    }
}
