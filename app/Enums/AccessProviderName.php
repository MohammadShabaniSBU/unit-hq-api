<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Access control vendor keys (S15-01).
 * Named AccessProviderName so it does not collide with
 * App\Support\Access\AccessProvider (PHP class names are case-insensitive).
 */
enum AccessProviderName: string
{
    case Sensorberg = 'sensorberg';

    public function label(): string
    {
        return match ($this) {
            self::Sensorberg => 'Sensorberg',
        };
    }
}
