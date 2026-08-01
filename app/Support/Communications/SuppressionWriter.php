<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\ChannelSuppression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer for channel_suppressions. Lift never deletes.
 */
final class SuppressionWriter
{
    public static function write(
        Channel $channel,
        string $address,
        SuppressionScope $scope,
        SuppressionReason $reason,
        ?int $sourceMessageId = null,
        ?int $createdBy = null,
    ): ChannelSuppression {
        $normalized = ContactChannelMatcher::normalize($channel, $address);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Cannot suppress an empty address.');
        }

        return DB::transaction(function () use (
            $channel,
            $normalized,
            $scope,
            $reason,
            $sourceMessageId,
            $createdBy,
        ): ChannelSuppression {
            $existing = ChannelSuppression::query()
                ->active()
                ->where('channel', $channel)
                ->where('address', $normalized)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Upgrade marketing → all when a harder fact arrives.
                if (
                    $existing->scope === SuppressionScope::Marketing
                    && $scope === SuppressionScope::All
                ) {
                    $existing->forceFill([
                        'lifted_at' => now(),
                        'lift_reason' => 'upgraded_to_all',
                    ])->save();
                } else {
                    return $existing;
                }
            }

            try {
                return ChannelSuppression::query()->create([
                    'channel' => $channel,
                    'address' => $normalized,
                    'scope' => $scope,
                    'reason' => $reason,
                    'source_message_id' => $sourceMessageId,
                    'created_by' => $createdBy,
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return ChannelSuppression::query()
                    ->active()
                    ->where('channel', $channel)
                    ->where('address', $normalized)
                    ->firstOrFail();
            }
        });
    }

    public static function fromDeliveryFailure(
        Channel $channel,
        string $address,
        Results\DeliveryStatus $status,
        bool $isPermanent,
        ?int $sourceMessageId = null,
    ): ?ChannelSuppression {
        if (! $isPermanent) {
            return null;
        }

        $reason = match ($status) {
            Results\DeliveryStatus::Spam => SuppressionReason::Complaint,
            Results\DeliveryStatus::Bounced => SuppressionReason::HardBounce,
            default => null,
        };

        if ($reason === null) {
            return null;
        }

        return self::write(
            $channel,
            $address,
            SuppressionScope::All,
            $reason,
            $sourceMessageId,
        );
    }

    public static function fromStopKeyword(
        string $address,
        ?int $sourceMessageId = null,
    ): ChannelSuppression {
        return self::write(
            Channel::Sms,
            $address,
            SuppressionScope::All,
            SuppressionReason::StopKeyword,
            $sourceMessageId,
        );
    }

    public static function fromUnsubscribe(
        string $address,
        ?int $sourceMessageId = null,
    ): ChannelSuppression {
        return self::write(
            Channel::Email,
            $address,
            SuppressionScope::Marketing,
            SuppressionReason::Unsubscribed,
            $sourceMessageId,
        );
    }

    public static function manual(
        Channel $channel,
        string $address,
        int $createdBy,
    ): ChannelSuppression {
        return self::write(
            $channel,
            $address,
            SuppressionScope::All,
            SuppressionReason::Manual,
            null,
            $createdBy,
        );
    }

    public static function lift(
        ChannelSuppression $suppression,
        int $liftedBy,
        string $liftReason,
    ): ChannelSuppression {
        if ($suppression->lifted_at !== null) {
            return $suppression;
        }

        $suppression->forceFill([
            'lifted_at' => now(),
            'lifted_by' => $liftedBy,
            'lift_reason' => $liftReason,
        ])->save();

        return $suppression->refresh();
    }

    public static function activeFor(Channel $channel, string $address): ?ChannelSuppression
    {
        $normalized = ContactChannelMatcher::normalize($channel, $address);
        if ($normalized === '') {
            return null;
        }

        return ChannelSuppression::query()
            ->active()
            ->where('channel', $channel)
            ->where('address', $normalized)
            ->first();
    }

    public static function blocks(Channel $channel, string $address, SendClass $class): ?ChannelSuppression
    {
        $active = self::activeFor($channel, $address);
        if ($active === null) {
            return null;
        }

        if ($active->scope === SuppressionScope::All) {
            return $active;
        }

        if (
            $active->scope === SuppressionScope::Marketing
            && $class === SendClass::Marketing
        ) {
            return $active;
        }

        return null;
    }

    /**
     * Inbox composer pre-warning: whether a transactional reply would be blocked.
     * Batched for list pages — keep ChannelSuppression reads inside this writer.
     *
     * @param  list<array{0: Channel, 1: string}>  $channelAddresses
     * @return array<string, bool> keyed by "{channel}|{normalizedAddress}"
     */
    public static function transactionalBlockedMap(array $channelAddresses): array
    {
        $pairs = [];

        foreach ($channelAddresses as [$channel, $address]) {
            $normalized = ContactChannelMatcher::normalize($channel, $address);
            if ($normalized === '') {
                continue;
            }

            $pairs[$channel->value.'|'.$normalized] = [$channel, $normalized];
        }

        if ($pairs === []) {
            return [];
        }

        $byChannel = [];
        foreach ($pairs as [$channel, $normalized]) {
            $byChannel[$channel->value][] = $normalized;
        }

        $rows = ChannelSuppression::query()
            ->active()
            ->where(function ($q) use ($byChannel): void {
                foreach ($byChannel as $channelValue => $addresses) {
                    $q->orWhere(function ($inner) use ($channelValue, $addresses): void {
                        $inner->where('channel', $channelValue)
                            ->whereIn('address', array_values(array_unique($addresses)));
                    });
                }
            })
            ->get();

        $map = [];
        foreach ($pairs as $key => [$channel, $normalized]) {
            $map[$key] = false;
            foreach ($rows as $row) {
                if ($row->channel !== $channel || $row->address !== $normalized) {
                    continue;
                }

                // Marketing-only does not block transactional replies.
                if ($row->scope === SuppressionScope::All) {
                    $map[$key] = true;
                    break;
                }
            }
        }

        return $map;
    }

    public static function isStopKeyword(?string $body): bool
    {
        if ($body === null) {
            return false;
        }

        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $body) ?? $body));
        if ($normalized === '') {
            return false;
        }

        /** @var list<string> $keywords */
        $keywords = config('communications.stop_keywords', ['STOP', 'BAJA', 'STOP TODO']);

        foreach ($keywords as $keyword) {
            if ($normalized === strtoupper(trim($keyword))) {
                return true;
            }
        }

        return false;
    }
}
