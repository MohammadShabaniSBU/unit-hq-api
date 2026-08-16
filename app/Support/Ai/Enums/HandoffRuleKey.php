<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum HandoffRuleKey: string
{
    case LegalOrComplaint = 'legal_or_complaint';
    case Delinquency = 'delinquency';
    case PriceNegotiation = 'price_negotiation';
    case MoveOutCommitment = 'move_out_commitment';
    case PaymentDispute = 'payment_dispute';
    case CustomerRequested = 'customer_requested';
    case ThirdParty = 'third_party';

    public function reason(): HandoffReason
    {
        return match ($this) {
            self::LegalOrComplaint, self::ThirdParty => HandoffReason::LegalOrComplaint,
            self::Delinquency => HandoffReason::Delinquency,
            self::PriceNegotiation => HandoffReason::PriceNegotiation,
            self::MoveOutCommitment, self::PaymentDispute => HandoffReason::UnsupportedIntent,
            self::CustomerRequested => HandoffReason::CustomerRequested,
        };
    }
}
