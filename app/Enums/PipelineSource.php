<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineSource: string
{
    case Operator = 'operator';
    case PublicLink = 'public_link';
    case AiAgent = 'ai_agent';
    case Automation = 'automation';
}
