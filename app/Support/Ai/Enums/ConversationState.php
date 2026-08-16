<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum ConversationState: string
{
    case Active = 'active';
    case AwaitingHuman = 'awaiting_human';
    case HandedOff = 'handed_off';
    case Closed = 'closed';
}
