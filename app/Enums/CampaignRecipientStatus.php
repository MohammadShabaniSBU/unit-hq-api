<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignRecipientStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
