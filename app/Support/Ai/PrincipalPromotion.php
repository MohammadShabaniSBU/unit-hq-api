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
 * Mid-turn upgrade of an anonymous customer principal after crm.create_contact.
 *
 * Promotion also fires when crm.create_contact dedupe-matches an existing
 * contact, so a webchat visitor who types an existing tenant's email becomes
 * channel_asserted for that contact. channel_asserted from a self-stated
 * identity is acceptable only because that level exposes nothing private
 * (billing.*, contract.summary, access.status all require verified) and its
 * only writes are crm.create_note (support agent only) and
 * sales.create_reservation in propose mode. Lowering any tool's floor to
 * channel_asserted must re-examine this path. OTP verification for webchat
 * is what closes it.
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
}
