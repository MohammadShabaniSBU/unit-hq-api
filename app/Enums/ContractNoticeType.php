<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractNoticeType: string
{
    case RateChange = 'rate_change';
    case MoveOutConfirmation = 'move_out_confirmation';
    case PaymentReminder = 'payment_reminder';
    case Overdue = 'overdue';
    case FinalDemand = 'final_demand';
    case Retention = 'retention';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
