<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum AgentMessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
