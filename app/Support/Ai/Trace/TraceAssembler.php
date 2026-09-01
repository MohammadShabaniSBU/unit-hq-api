<?php

declare(strict_types=1);

namespace App\Support\Ai\Trace;

use App\Models\AgentConversation;
use App\Models\AgentGuardrailEvent;
use App\Models\AgentHandoff;
use App\Models\AgentPrincipalPromotion;
use App\Models\AgentToolInvocation;
use App\Models\AiUsageEvent;
use App\Support\Ai\AiUsageCost;
use App\Support\Ai\Tools\ToolResult;
use DateTimeInterface;

final class TraceAssembler
{
    /**
     * Ordered canonical export. Consumers sort by `seq`, never by `turn`.
     *
     * @return list<array<string, mixed>>
     */
    public static function for(AgentConversation $conversation): array
    {
        $conversation->loadMissing([
            'toolInvocations.pendingAction',
            'handoffs',
            'guardrailEvents',
            'usageEvents',
            'principalPromotions',
        ]);

        $rows = [];

        foreach ($conversation->toolInvocations as $invocation) {
            $rows[] = self::toolRow($invocation);
        }

        foreach ($conversation->guardrailEvents as $event) {
            $rows[] = self::guardrailRow($event);
        }

        foreach ($conversation->usageEvents as $event) {
            $rows[] = self::usageRow($event);
        }

        foreach ($conversation->handoffs as $handoff) {
            $rows[] = self::handoffRow($handoff);
        }

        foreach ($conversation->principalPromotions as $promotion) {
            $rows[] = self::promotionRow($promotion);
        }

        usort($rows, static function (array $a, array $b): int {
            $seqA = (int) ($a['seq'] ?? 0);
            $seqB = (int) ($b['seq'] ?? 0);
            if ($seqA !== $seqB) {
                return $seqA <=> $seqB;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolRow(AgentToolInvocation $invocation): array
    {
        $result = is_array($invocation->result) ? $invocation->result : null;
        $error = ToolResult::errorFromTrace($result);
        $replayed = is_array($result) && ($result['replayed'] ?? false) === true;

        return [
            ...self::envelope(
                $invocation->agent_conversation_id,
                $invocation->turn,
                $invocation->seq,
                $invocation->agent_conversation_message_id,
                $invocation->model,
                $invocation->prompt_version,
                $invocation->created_at,
            ),
            'kind' => 'tool',
            'id' => $invocation->id,
            'tool_call_id' => $invocation->tool_call_id,
            'tool_key' => $invocation->tool_key,
            'arguments' => is_array($invocation->arguments) ? $invocation->arguments : [],
            'status' => $invocation->status instanceof \BackedEnum
                ? $invocation->status->value
                : $invocation->status,
            'denied_reason' => $invocation->denied_reason instanceof \BackedEnum
                ? $invocation->denied_reason->value
                : $invocation->denied_reason,
            'duration_ms' => $invocation->duration_ms,
            'result_summary' => $invocation->result_summary,
            'result' => $result,
            'invocation_id' => $invocation->id,
            'pending_action_id' => $invocation->pendingAction?->id,
            'replayed' => $replayed,
            'entities' => array_map(
                static fn ($ref): array => $ref->toArray(),
                ToolResult::entitiesFromTrace($result),
            ),
            'error_code' => $error?->errorCode->value,
            'recovery' => $error?->recovery,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function guardrailRow(AgentGuardrailEvent $event): array
    {
        return [
            ...self::envelope(
                $event->agent_conversation_id,
                $event->turn,
                $event->seq,
                $event->agent_conversation_message_id,
                $event->model,
                $event->prompt_version,
                $event->created_at,
            ),
            'kind' => 'guardrail',
            'id' => $event->id,
            'guard' => $event->guard,
            'verdict' => $event->verdict,
            'detail' => $event->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function usageRow(AiUsageEvent $event): array
    {
        $cost = AiUsageCost::forEvent($event);

        return [
            ...self::envelope(
                (int) $event->agent_conversation_id,
                $event->turn,
                $event->seq,
                $event->agent_conversation_message_id,
                $event->model,
                $event->prompt_version,
                $event->started_at ?? $event->created_at,
            ),
            'kind' => 'usage',
            'id' => $event->id,
            'input_tokens' => $event->input_tokens,
            'cached_input_tokens' => $event->cached_input_tokens,
            'output_tokens' => $event->output_tokens,
            'estimated_cost' => $cost['estimated_cost'] ?? null,
            'currency' => $cost['currency'] ?? null,
            'messages_sent' => is_array($event->context) ? ($event->context['messages_sent'] ?? null) : null,
            'messages_evicted' => is_array($event->context) ? ($event->context['messages_evicted'] ?? null) : null,
            'summary_chars' => is_array($event->context) ? ($event->context['summary_chars'] ?? null) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function handoffRow(AgentHandoff $handoff): array
    {
        return [
            ...self::envelope(
                $handoff->agent_conversation_id,
                $handoff->turn,
                $handoff->seq,
                $handoff->agent_conversation_message_id,
                $handoff->model,
                $handoff->prompt_version,
                $handoff->created_at,
            ),
            'kind' => 'handoff',
            'id' => $handoff->id,
            'reason' => $handoff->reason instanceof \BackedEnum
                ? $handoff->reason->value
                : $handoff->reason,
            'trigger_source' => $handoff->trigger_source instanceof \BackedEnum
                ? $handoff->trigger_source->value
                : $handoff->trigger_source,
            'detail' => $handoff->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function promotionRow(AgentPrincipalPromotion $promotion): array
    {
        return [
            ...self::envelope(
                $promotion->agent_conversation_id,
                $promotion->turn,
                $promotion->seq,
                $promotion->agent_conversation_message_id,
                $promotion->model,
                $promotion->prompt_version,
                $promotion->created_at,
            ),
            'kind' => 'promotion',
            'id' => $promotion->id,
            'from' => $promotion->from_level instanceof \BackedEnum
                ? $promotion->from_level->value
                : $promotion->from_level,
            'to' => $promotion->to_level instanceof \BackedEnum
                ? $promotion->to_level->value
                : $promotion->to_level,
            'method' => $promotion->method,
        ];
    }

    /**
     * @return array{
     *     conversation_id: int,
     *     turn: int|null,
     *     seq: int|null,
     *     message_id: int|null,
     *     model: string|null,
     *     prompt_version: string|null,
     *     occurred_at: string|null
     * }
     */
    private static function envelope(
        int $conversationId,
        ?int $turn,
        ?int $seq,
        ?int $messageId,
        ?string $model,
        ?string $promptVersion,
        mixed $occurredAt,
    ): array {
        $at = null;
        if ($occurredAt instanceof DateTimeInterface) {
            $at = $occurredAt->format('Y-m-d H:i:s');
        } elseif (is_string($occurredAt) && $occurredAt !== '') {
            $at = $occurredAt;
        }

        return [
            'conversation_id' => $conversationId,
            'turn' => $turn,
            'seq' => $seq,
            'message_id' => $messageId,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'occurred_at' => $at,
        ];
    }
}
