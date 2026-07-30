<?php

declare(strict_types=1);

namespace App\Enums;

enum ChargeType: string
{
    case Rent = 'rent';
    case Insurance = 'insurance';
    case Deposit = 'deposit';
    case LateFee = 'late_fee';
    case LienFee = 'lien_fee';
    case Other = 'other';
    case Adjustment = 'adjustment';
    case WriteOff = 'write_off';
    case Refund = 'refund';

    public function isRevenue(): bool
    {
        return match ($this) {
            self::Deposit, self::WriteOff, self::Refund => false,
            default => true,
        };
    }
}
