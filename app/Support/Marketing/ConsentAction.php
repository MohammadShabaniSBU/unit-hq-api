<?php

declare(strict_types=1);

namespace App\Support\Marketing;

enum ConsentAction: string
{
    case Granted = 'granted';
    case Pending = 'pending';
    case Withdrawn = 'withdrawn';
}
