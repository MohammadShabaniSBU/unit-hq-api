<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum AgentAudience: string
{
    case Internal = 'internal';
    case Customer = 'customer';
}
