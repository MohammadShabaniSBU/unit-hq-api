<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightSiteScopeMode: string
{
    case Inherit = 'inherit';
    case Ignore = 'ignore';
}
