<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractEndedReason: string
{
    case Vacated = 'vacated';
    case NonPayment = 'non_payment';
    case TransferredOut = 'transferred_out';
    case OperatorTerminated = 'operator_terminated';
    case Cancelled = 'cancelled';
}
