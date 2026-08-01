<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Explicit value source for ConditionEvaluator — never ambient.
 * @see docs/automation-conditions.md Rule 5
 */
enum ConditionSource: string
{
    case Snapshot = 'snapshot';
    case Live = 'live';
}
