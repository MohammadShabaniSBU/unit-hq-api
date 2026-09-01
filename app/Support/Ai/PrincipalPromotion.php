<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\LogChannel;
use App\Models\AgentConversation;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\AgentWriteAttribution;
use App\Support\Ai\Tools\ToolResult;

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
 */
final class PrincipalPromotion
{
    public static function afterToolResult(
        AgentConversation $conversation,
        AgentPrincipal $principal,
        string $toolKey,
        ToolResult $result,
        ?AgentContext $ctx,
    ): ?AgentPrincipal {
        if ($toolKey === 'identity.verify_code') {
            return self::promoteVerified($conversation, $principal, $result, $ctx);
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
            ],
        );

        return $conversation->principal();
    }

    private static function promoteVerified(
        AgentConversation $conversation,
        AgentPrincipal $principal,
        ToolResult $result,
        ?AgentContext $ctx,
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

        return $conversation->principal();
    }
}
