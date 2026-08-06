<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightValidationStatus: string
{
    case Unknown = 'unknown';
    case Valid = 'valid';
    case ResourceMissing = 'resource_missing';
    case ParamMismatch = 'param_mismatch';
    case Unreachable = 'unreachable';
}
