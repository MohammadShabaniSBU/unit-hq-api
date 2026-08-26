<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum OutsideHoursPolicy: string
{
    case Inbox = 'inbox';
    case Answer = 'answer';
}
