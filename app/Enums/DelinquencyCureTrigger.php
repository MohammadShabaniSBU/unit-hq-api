<?php

declare(strict_types=1);

namespace App\Enums;

enum DelinquencyCureTrigger: string
{
    case Payment = 'payment';
    case WriteOff = 'write_off';
    case Vacated = 'vacated';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
