<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentPendingAction;
use App\Models\AgentToolInvocation;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Tools\ToolResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persist a propose-mode result as a pending action after the invocation row exists.
 */
final class PendingActionRecorder
{
    public function record(AgentToolInvocation $invocation, ToolResult $result): AgentPendingAction
    {
        if ($result->deniedReason !== ToolDeniedReason::RequiresApproval) {
            throw new InvalidArgumentException('PendingActionRecorder requires a RequiresApproval result.');
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($result->data['payload'] ?? null) ? $result->data['payload'] : [];
        /** @var array<string, mixed>|null $preview */
        $preview = is_array($result->data['preview'] ?? null) ? $result->data['preview'] : null;

        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : 0;
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Pending action is missing site_id.');
        }

        $ttl = (int) config('agents.pending_action_ttl_minutes', 120);

        return DB::transaction(function () use ($invocation, $payload, $preview, $siteId, $ttl): AgentPendingAction {
            $pending = AgentPendingAction::query()->create([
                'agent_conversation_id' => $invocation->agent_conversation_id,
                'agent_tool_invocation_id' => $invocation->id,
                'ai_agent_id' => $invocation->conversation->ai_agent_id,
                'site_id' => $siteId,
                'tool_key' => $invocation->tool_key,
                'payload' => $payload,
                'preview' => $preview,
                'status' => PendingActionStatus::Pending,
                'expires_at' => now()->addMinutes($ttl),
            ]);

            AgentPendingAction::query()
                ->where('agent_conversation_id', $invocation->agent_conversation_id)
                ->where('tool_key', $invocation->tool_key)
                ->where('status', PendingActionStatus::Pending)
                ->where('id', '<>', $pending->id)
                ->update(['status' => PendingActionStatus::Superseded]);

            return $pending;
        });
    }
}
