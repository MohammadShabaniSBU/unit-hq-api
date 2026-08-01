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
