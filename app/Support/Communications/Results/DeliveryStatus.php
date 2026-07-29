<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

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
}
