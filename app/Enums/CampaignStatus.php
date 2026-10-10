<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Paused = 'paused';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
}
