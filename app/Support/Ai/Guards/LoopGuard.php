<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\Message;
use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Communications\MessageSource;

final class LoopGuard
{
    public function evaluate(AgentConversation $conversation, AgentDefinition $definition, AiAgent $agent): ?HandoffMatch
    {
        $maxTurns = (int) ($agent->settings['max_turns'] ?? $definition->maxTurns());
        $assistantCount = $conversation->messages()
            ->where('role', AgentMessageRole::Assistant->value)
            ->count();

        if ($assistantCount >= $maxTurns) {
            return new HandoffMatch(
                HandoffReason::TurnLimit,
                CannedReply::Budget,
                ['detail' => 'max_turns'],
                'loop',
            );
        }

        if ($this->consecutiveAssistantWithoutUser($conversation) > 1) {
            return new HandoffMatch(
                HandoffReason::RepeatedFailure,
                CannedReply::Handoff,
                ['detail' => 'consecutive_assistant'],
                'loop',
            );
        }

        if ($this->lastTwoTurnsEndedInToolFailure($conversation)) {
            return new HandoffMatch(
                HandoffReason::RepeatedFailure,
                CannedReply::Handoff,
                ['detail' => 'repeated_tool_failure'],
                'loop',
            );
        }

        return null;
    }

    /**
     * Never reply to auto-generated inbound, or to a message the agent itself sent.
     */
    public static function shouldIgnoreInbound(Message $message): bool
    {
        if ($message->auto_generated) {
            return true;
        }

        return $message->source === MessageSource::AiAgent;
    }

    private function consecutiveAssistantWithoutUser(AgentConversation $conversation): int
    {
        $trailing = 0;
        foreach ($conversation->messages()->get()->reverse() as $message) {
            if ($message->role === AgentMessageRole::Tool) {
                continue;
            }
            if ($message->role === AgentMessageRole::Assistant) {
                if ($message->tool_calls !== null && $message->tool_calls !== []) {
                    continue;
                }
                $trailing++;

                continue;
            }

            break;
        }

        return $trailing;
    }

    private function lastTwoTurnsEndedInToolFailure(AgentConversation $conversation): bool
    {
        $invocations = $conversation->toolInvocations()
            ->orderByDesc('id')
            ->get();

        $byMessage = [];
        foreach ($invocations as $invocation) {
            $messageId = $invocation->agent_conversation_message_id;
            if ($messageId === null) {
                continue;
            }
            if (! array_key_exists($messageId, $byMessage) && count($byMessage) >= 2) {
                continue;
            }
            $byMessage[$messageId][] = $invocation->status;
        }

        if (count($byMessage) < 2) {
            return false;
        }

        $lastTwo = array_slice($byMessage, 0, 2, true);
        foreach ($lastTwo as $statuses) {
            foreach ($statuses as $status) {
                if ($status !== ToolInvocationStatus::Error && $status !== ToolInvocationStatus::NotFound) {
                    return false;
                }
            }
        }

        return true;
    }
}
