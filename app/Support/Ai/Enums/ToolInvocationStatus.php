<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum ToolInvocationStatus: string
{
    case Ok = 'ok';
    case Denied = 'denied';
    case NotFound = 'not_found';
    case Error = 'error';
}
