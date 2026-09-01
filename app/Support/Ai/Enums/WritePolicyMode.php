<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum WritePolicyMode: string
{
    case Off = 'off';
    case Propose = 'propose';
    case Commit = 'commit';

    public function rank(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Propose => 1,
            self::Commit => 2,
        };
    }
}
