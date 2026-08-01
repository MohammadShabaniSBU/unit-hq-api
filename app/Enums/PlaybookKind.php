<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaybookKind: string
{
    case DebtProcess = 'debt_process';
    case LeadChase = 'lead_chase';
}
