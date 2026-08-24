<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum PendingActionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Superseded = 'superseded';
}
