<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Saved payment instrument kinds on `payment_methods.type`.
 * Distinct from App\Enums\PaymentMethod (ledger rail on payments).
 */
enum PaymentInstrumentType: string
{
    case StripeCard = 'stripe_card';
    case StripeSepa = 'stripe_sepa';
    case BankSdd = 'bank_sdd';
    case Manual = 'manual';

    /** Types accepted by validation this sprint. */
    public static function activeValues(): array
    {
        return [
            self::StripeCard->value,
            self::Manual->value,
        ];
    }
}
