<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

use App\Support\Communications\MessageStatus;

enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Read = 'read';
    case Bounced = 'bounced';
    case Failed = 'failed';
    case Spam = 'spam';
    case Unsubscribed = 'unsubscribed';

    /**
     * Forward-only lattice rank. Null means "history only" — never moves
     * messages.status (e.g. unsubscribed).
     */
    public function rank(): ?int
    {
        return match ($this) {
            self::Queued => 10,
            self::Sent => 20,
            self::Delivered => 30,
            self::Opened, self::Read => 40,
            self::Clicked => 50,
            self::Failed, self::Bounced, self::Spam => 100,
            self::Unsubscribed => null,
        };
    }

    /**
     * Map onto MessageStatus. Null when the event must not change the column.
     */
    public function toMessageStatus(): ?MessageStatus
    {
        return match ($this) {
            self::Queued => MessageStatus::Queued,
            self::Sent => MessageStatus::Sent,
            self::Delivered => MessageStatus::Delivered,
            self::Opened, self::Read => MessageStatus::Opened,
            self::Clicked => MessageStatus::Clicked,
            self::Bounced => MessageStatus::Bounced,
            self::Failed => MessageStatus::Failed,
            self::Spam => MessageStatus::Spam,
            self::Unsubscribed => null,
        };
    }
}
