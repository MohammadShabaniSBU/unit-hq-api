<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum SuppressionReason: string
{
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case StopKeyword = 'stop_keyword';
    case Unsubscribed = 'unsubscribed';
    case Manual = 'manual';
}
