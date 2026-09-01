<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\LogChannel;
use App\Models\AgentConversation;
use App\Models\AgentPrincipalPromotion;
use App\Models\AgentToolInvocation;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\AgentWriteAttribution;
use App\Support\Ai\Tools\ToolResult;
use App\Support\Ai\Trace\TraceSeq;

/**
 * Mid-turn upgrade of a customer principal.
 *
 * crm.create_contact (including a dedupe match) promotes anonymous →
 * channel_asserted. A self-stated identity at that level is acceptable
 * only because that level exposes nothing private (billing.*,
 * contract.summary, access.status all require verified) and its only write
 * is sales.create_reservation in propose mode. crm.create_note used to be
 * listed here; it never landed on inbound traffic because
 * AgentWriteAttribution::requireEmployeeId() blocked it independently of
 * the verification gate, and its floor is now verified. Lowering any
 * tool's floor to channel_asserted must re-examine this path.
 *
 * identity.verify_code with an ok result promotes channel_asserted →
 * verified. OTP verification for webchat is what closes the gap.
 *
 * Each successful promotion writes an append-only
 * `agent_principal_promotions` row (trace kind `promotion`). No SSE event
 * is emitted — the row arrives through hydrate() after the turn (S27-05).
 */
final class PrincipalPromotion
{
    public static function afterToolResult(
        AgentConversation $conversation,
        AgentPrincipal $principal,
        string $toolKey,
        ToolResult $result,
        ?AgentContext $ctx,
        ?AgentToolInvocation $invocation = null,
    ): ?AgentPrincipal {
        if ($toolKey === 'identity.verify_code') {
            return self::promoteVerified($conversation, $principal, $result, $ctx, $invocation);
        }

        if ($toolKey !== 'crm.create_contact') {
            return null;
        }
        if ($result->status !== ToolInvocationStatus::Ok || $result->resultType !== 'contact') {
            return null;
        }
        if ($result->resultId === null) {
            return null;
        }
        if ($principal->audience !== AgentAudience::Customer) {
            return null;
        }
        if ($conversation->contact_id !== null) {
            return null;
        }
        if ($principal->verification->satisfies(VerificationLevel::ChannelAsserted)) {
            return null;
        }

        $from = $principal->verification;
        $conversation->contact_id = $result->resultId;
        $conversation->verification_level = VerificationLevel::ChannelAsserted;
        $conversation->save();

        AgentWriteAttribution::log(
            LogChannel::Ai,
            'agent.conversation.principal_promoted',
            $conversation,
            $ctx,
            [
                'from' => $from->value,
                'to' => VerificationLevel::ChannelAsserted->value,
                'contact_id' => $result->resultId,
                'method' => 'contact_created',
            ],
        );

        self::recordTrace($conversation, $from, VerificationLevel::ChannelAsserted, 'contact_created', $invocation);

        return $conversation->principal();
    }

    private static function promoteVerified(
        AgentConversation $conversation,
        AgentPrincipal $principal,
        ToolResult $result,
        ?AgentContext $ctx,
        ?AgentToolInvocation $invocation,
    ): ?AgentPrincipal {
        if ($result->status !== ToolInvocationStatus::Ok) {
            return null;
        }
        if ($principal->audience !== AgentAudience::Customer) {
            return null;
        }
        if ($principal->contactId === null || $conversation->contact_id !== $principal->contactId) {
            return null;
        }
        if ($principal->verification->satisfies(VerificationLevel::Verified)) {
            return null;
        }

        $from = $principal->verification;
        $conversation->verification_level = VerificationLevel::Verified;
        $conversation->save();

        AgentWriteAttribution::log(
            LogChannel::Ai,
            'agent.conversation.principal_promoted',
            $conversation,
            $ctx,
            [
                'from' => $from->value,
                'to' => VerificationLevel::Verified->value,
                'contact_id' => $principal->contactId,
                'method' => 'otp',
            ],
        );

        self::recordTrace($conversation, $from, VerificationLevel::Verified, 'otp', $invocation);

        return $conversation->principal();
    }

    private static function recordTrace(
        AgentConversation $conversation,
        VerificationLevel $from,
        VerificationLevel $to,
        string $method,
        ?AgentToolInvocation $invocation,
    ): void {
        AgentPrincipalPromotion::query()->create([
            'agent_conversation_id' => $conversation->id,
            'agent_conversation_message_id' => $invocation?->agent_conversation_message_id,
            'turn' => $invocation?->turn,
            'seq' => TraceSeq::max($conversation->id) + 1,
            'from_level' => $from,
            'to_level' => $to,
            'method' => $method,
            'model' => $invocation?->model,
            'prompt_version' => $invocation?->prompt_version,
        ]);
    }
}
