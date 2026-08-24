<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum WritePolicyMode: string
{
    case Off = 'off';
    case Propose = 'propose';
    case Commit = 'commit';
}
