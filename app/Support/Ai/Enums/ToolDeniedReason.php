<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum ToolDeniedReason: string
{
    case Verification = 'verification';
    case Ownership = 'ownership';
    case NotAllowedForAgent = 'not_allowed_for_agent';
    case SiteScope = 'site_scope';
    case QuotaExceeded = 'quota_exceeded';
    case RequiresApproval = 'requires_approval';
    case UnlicensedArgument = 'unlicensed_argument';
}
