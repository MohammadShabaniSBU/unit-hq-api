<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessPointType: string
{
    case UnitDoor = 'unit_door';
    case Gate = 'gate';
    case Zone = 'zone';

    public function isSiteLevel(): bool
    {
        return $this === self::Gate || $this === self::Zone;
    }
}
