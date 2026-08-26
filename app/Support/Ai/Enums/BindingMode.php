<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum BindingMode: string
{
    case Off = 'off';
    case Draft = 'draft';
    case Auto = 'auto';
}
