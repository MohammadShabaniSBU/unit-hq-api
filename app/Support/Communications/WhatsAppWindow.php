<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\MessageThread;
use Carbon\CarbonInterface;

/**
 * Meta customer-service window: open for 24h after last inbound.
 * Computed from last_inbound_at — never stored.
 */
final class WhatsAppWindow
{
    public static function isOpen(MessageThread $thread): bool
    {
        $closesAt = self::closesAt($thread);

        return $closesAt !== null && now()->lt($closesAt);
    }

    public static function closesAt(MessageThread $thread): ?CarbonInterface
    {
        if ($thread->last_inbound_at === null) {
            return null;
        }

        return $thread->last_inbound_at->copy()->addDay();
    }

    /**
     * Inbox payload: {open, closes_at}|null. Null for non-WhatsApp threads.
     *
     * @return array{open: bool, closes_at: string|null}|null
     */
    public static function payload(MessageThread $thread): ?array
    {
        $channel = $thread->channel instanceof Channel
            ? $thread->channel
            : Channel::tryFrom((string) $thread->channel);

        if ($channel !== Channel::Whatsapp) {
            return null;
        }

        $closesAt = self::closesAt($thread);

        return [
            'open' => self::isOpen($thread),
            'closes_at' => $closesAt?->toIso8601String(),
        ];
    }
}
