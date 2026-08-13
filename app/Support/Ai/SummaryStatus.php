<?php

declare(strict_types=1);

namespace App\Support\Ai;

enum SummaryStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isInFlight(): bool
    {
        return match ($this) {
            self::Queued, self::Running => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            default => false,
        };
    }
}
