<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Events\ChannelDeliveryFailed;
use App\Models\AutomationRunStep;
use App\Models\Message;
use App\Models\OfferDelivery;
use App\Models\SystemEvent;
use App\Support\Communications\Results\DeliveryEvent;
use App\Support\Communications\Results\DeliveryStatus;
use Illuminate\Support\Facades\DB;

/**
 * Applies a normalised DeliveryEvent onto Message / Interaction / OfferDelivery
 * / playbook step output. Called from ProcessDeliveryWebhookEvent.
 */
final class DeliveryEventApplier
{
    /**
     * @return Message|null  matched message, or null when unmatched
     */
    public static function apply(Provider $provider, DeliveryEvent $event): ?Message
    {
        return DB::transaction(function () use ($provider, $event): ?Message {
            $message = Message::query()
                ->where('provider', $provider)
                ->where('provider_message_id', $event->providerMessageId)
                ->first();

            self::updateOfferDelivery($event);

            if ($message === null) {
                return null;
            }

            self::appendHistory($message, $event);
            self::advanceStatus($message, $event);
            $message->save();

            $message->interaction?->touch();

            if ($message->source === MessageSource::Playbook) {
                self::backfillPlaybookStep($message, $event);
            }

            if (
                $event->status === DeliveryStatus::Bounced
                || $event->status === DeliveryStatus::Spam
            ) {
                $channel = $message->thread?->channel ?? Channel::Email;
                $address = $event->recipient ?? $message->to_address;

                event(new ChannelDeliveryFailed(
                    messageId: (int) $message->id,
                    channel: $channel,
                    address: $address,
                    isPermanent: $event->isPermanent,
                    status: $event->status,
                    reason: $event->reason,
                ));
            }

            if ($event->status === DeliveryStatus::Unsubscribed) {
                $address = $event->recipient ?? $message->to_address;
                SuppressionWriter::fromUnsubscribe($address, (int) $message->id);
            }

            return $message->fresh(['interaction', 'thread']);
        });
    }

    public static function recordUnmatched(
        Provider $provider,
        DeliveryEvent $event,
        ?int $accountId = null,
    ): void {
        SystemEvent::record('comms.webhook.unmatched', null, [
            'provider' => $provider->value,
            'provider_message_id' => $event->providerMessageId,
            'provider_event_id' => $event->providerEventId,
            'raw_status' => $event->rawStatus,
            'communication_account_id' => $accountId,
        ]);
    }

    private static function appendHistory(Message $message, DeliveryEvent $event): void
    {
        $history = $message->delivery_events ?? [];
        $history[] = [
            'status' => $event->status->value,
            'raw_status' => $event->rawStatus,
            'provider_event_id' => $event->providerEventId,
            'occurred_at' => $event->occurredAt?->toIso8601String(),
            'reason' => $event->reason,
            'is_permanent' => $event->isPermanent,
            'recorded_at' => now()->toIso8601String(),
        ];
        $message->delivery_events = $history;
    }

    private static function advanceStatus(Message $message, DeliveryEvent $event): void
    {
        $newRank = $event->status->rank();
        $mapped = $event->status->toMessageStatus();

        if ($newRank === null || $mapped === null) {
            return;
        }

        $currentRank = $message->status instanceof MessageStatus
            ? $message->status->rank()
            : null;

        if ($currentRank === null || $newRank > $currentRank) {
            $message->status = $mapped;
        }
    }

    private static function backfillPlaybookStep(Message $message, DeliveryEvent $event): void
    {
        $stepId = $message->source_ref['automation_run_step_id'] ?? null;
        if (! is_int($stepId) && ! (is_string($stepId) && ctype_digit($stepId))) {
            return;
        }

        $step = AutomationRunStep::query()->find((int) $stepId);
        if ($step === null) {
            return;
        }

        $output = $step->output ?? [];
        $output['delivery_status'] = $event->status->value;
        $output['delivery_raw_status'] = $event->rawStatus;
        if ($event->status === DeliveryStatus::Delivered) {
            $output['delivered_at'] = ($event->occurredAt ?? now())->toIso8601String();
        }
        if ($event->reason !== null) {
            $output['delivery_reason'] = $event->reason;
        }

        $step->output = $output;
        $step->save();
    }

    private static function updateOfferDelivery(DeliveryEvent $event): void
    {
        $delivery = OfferDelivery::query()
            ->where('provider_message_id', $event->providerMessageId)
            ->first();

        if ($delivery === null) {
            return;
        }

        $mapped = match ($event->status) {
            DeliveryStatus::Queued => 'queued',
            DeliveryStatus::Sent => 'sent',
            DeliveryStatus::Delivered,
            DeliveryStatus::Opened,
            DeliveryStatus::Clicked,
            DeliveryStatus::Read => 'delivered',
            DeliveryStatus::Bounced,
            DeliveryStatus::Failed,
            DeliveryStatus::Spam => 'failed',
            DeliveryStatus::Unsubscribed => null,
        };

        if ($mapped === null) {
            return;
        }

        $delivery->delivery_status = $mapped;

        if (
            in_array($mapped, ['delivered', 'failed'], true)
            && $delivery->delivered_at === null
            && $event->status === DeliveryStatus::Delivered
        ) {
            $delivery->delivered_at = $event->occurredAt?->toMutable() ?? now();
        }

        $delivery->save();
    }
}
