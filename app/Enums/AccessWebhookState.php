<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessWebhookState: string
{
    case Unconfigured = 'unconfigured';
    case Configured = 'configured';
}
