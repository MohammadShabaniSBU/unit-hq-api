<?php

declare(strict_types=1);

namespace App\Enums;

enum DepositSettlementOutcome: string
{
    case Released = 'released';
    case Deducted = 'deducted';
    case Forfeited = 'forfeited';
}
