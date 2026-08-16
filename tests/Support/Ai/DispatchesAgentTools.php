<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Models\AgentConversation;
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

    protected function writeContext(AgentPrincipal $principal, string $agentKey, ?Employee $employee = null): AgentContext
    {
        $employee ??= Employee::factory()->create();
        $agent = AiAgent::factory()->create([
            'key' => $agentKey.'-'.uniqid(),
            'name' => $agentKey,
        ]);

        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'audience' => $principal->audience,
            'origin' => AgentOrigin::Demo,
            'channel' => AgentChannel::Webchat,
            'employee_id' => $principal->audience === AgentAudience::Internal ? $principal->employeeId : null,
            'created_by_employee_id' => $employee->id,
            'contact_id' => $principal->contactId,
            'site_id' => $principal->siteId,
            'verification_level' => $principal->verification,
            'state' => ConversationState::Active,
            'locale' => $principal->locale,
        ]);

        return new AgentContext(
            $principal,
            ChannelProfile::for(AgentChannel::Webchat),
            app(AgentRegistry::class)->get($agentKey),
            $conversation,
            $agent,
        );
    }
}
