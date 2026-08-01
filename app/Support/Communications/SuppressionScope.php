<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum SuppressionScope: string
{
    case All = 'all';
    case Marketing = 'marketing';
}
