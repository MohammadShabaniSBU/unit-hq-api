<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum AgentOrigin: string
{
    case Demo = 'demo';
    case Inbox = 'inbox';
    case Webchat = 'webchat';
}
