<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum MessageSource: string
{
    case Manual = 'manual';
    case Offer = 'offer';
    case Playbook = 'playbook';
    case Automation = 'automation';
    case System = 'system';
    case AiAgent = 'ai_agent';
}
