<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Failed = 'failed';
    case Spam = 'spam';
    case Received = 'received';
}
