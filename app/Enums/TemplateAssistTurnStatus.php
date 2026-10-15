<?php

declare(strict_types=1);

namespace App\Enums;

enum TemplateAssistTurnStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Applied = 'applied';
    case Undone = 'undone';
    case Conflict = 'conflict';
    case Discarded = 'discarded';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Running => $next === self::Completed || $next === self::Failed,
            self::Completed => $next === self::Applied || $next === self::Conflict || $next === self::Discarded,
            self::Applied => $next === self::Undone,
            self::Undone => $next === self::Applied,
            self::Failed, self::Conflict, self::Discarded => false,
        };
    }
}
