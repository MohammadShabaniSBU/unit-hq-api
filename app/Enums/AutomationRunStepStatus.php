<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationRunStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Skipped => true,
            default => false,
        };
    }
}
