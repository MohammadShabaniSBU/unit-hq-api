<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\AircallUserLink;
use App\Models\CallIntent;
use App\Models\Message;
use App\Support\Communications\Results\InboundMessage;

/**
 * Join an outbound call.created webhook message back to the click that started it.
 */
final class CallIntentCorrelator
{
    public static function correlate(Message $message, InboundMessage $inbound): void
    {
        if ($inbound->channel !== Channel::Call) {
            return;
        }

        if ($inbound->direction !== MessageDirection::Outbound) {
            return;
        }

        $event = $inbound->sourceRef['event'] ?? null;
        if ($event !== 'call.created') {
            return;
        }

        $callId = $inbound->providerMessageId;
        $intent = CallIntent::query()
            ->where('status', CallIntent::STATUS_REQUESTED)
            ->where('aircall_call_id', $callId)
            ->first();

        $correlation = 'exact';

        if ($intent === null) {
            $intent = self::heuristicMatch($inbound);
            $correlation = 'heuristic';
        }

        if ($intent === null) {
            return;
        }

        $intent->forceFill([
            'message_id' => $message->id,
            'aircall_call_id' => $intent->aircall_call_id ?? $callId,
            'status' => CallIntent::STATUS_CORRELATED,
        ])->save();

        /** @var array<string, mixed> $ref */
        $ref = is_array($message->source_ref) ? $message->source_ref : [];
        $ref['call_intent'] = [
            'id' => $intent->id,
            'context_type' => $intent->context_type,
            'context_id' => $intent->context_id,
            'correlation' => $correlation,
        ];

        $message->forceFill(['source_ref' => $ref])->save();
    }

    private static function heuristicMatch(InboundMessage $inbound): ?CallIntent
    {
        $user = $inbound->sourceRef['call']['user'] ?? null;
        if (! is_array($user) || ! isset($user['id'])) {
            return null;
        }

        $aircallUserId = (string) $user['id'];
        $link = AircallUserLink::query()
            ->where('aircall_user_id', $aircallUserId)
            ->first();

        if ($link === null) {
            return null;
        }

        $toNumber = ContactChannelMatcher::normalize(Channel::Call, $inbound->to);
        if ($toNumber === '') {
            return null;
        }

        $cutoff = now()->subMinutes(2);

        return CallIntent::query()
            ->where('status', CallIntent::STATUS_REQUESTED)
            ->where('employee_id', $link->employee_id)
            ->where('created_at', '>=', $cutoff)
            ->get()
            ->first(function (CallIntent $intent) use ($toNumber): bool {
                return ContactChannelMatcher::normalize(Channel::Call, $intent->to_number) === $toNumber;
            });
    }
}
