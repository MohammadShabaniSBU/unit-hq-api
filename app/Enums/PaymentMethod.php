<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case CardExternal = 'card_external';
    /** Stripe rail A — written only by ProcessStripeWebhookEvent. */
    case StripeCard = 'stripe_card';
}
