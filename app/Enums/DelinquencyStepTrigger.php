<?php

declare(strict_types=1);

namespace App\Enums;

enum DelinquencyStepTrigger: string
{
    case Ladder = 'ladder';
    case Manual = 'manual';
    case Cure = 'cure';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
