<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum AccountScope: string
{
    case Company = 'company';
    case Site = 'site';
}
