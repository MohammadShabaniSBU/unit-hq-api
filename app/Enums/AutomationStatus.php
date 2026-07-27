<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';

    public function isRunnable(): bool
    {
        return $this === self::Active;
    }
}
