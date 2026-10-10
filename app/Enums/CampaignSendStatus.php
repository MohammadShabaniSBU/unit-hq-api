<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignSendStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Materialising = 'materialising';
    case Sending = 'sending';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
