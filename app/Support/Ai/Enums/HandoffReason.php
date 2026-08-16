<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum HandoffReason: string
{
    case LegalOrComplaint = 'legal_or_complaint';
    case Delinquency = 'delinquency';
    case PriceNegotiation = 'price_negotiation';
    case VerificationRequired = 'verification_required';
    case UnsupportedIntent = 'unsupported_intent';
    case GroundingFailure = 'grounding_failure';
    case RepeatedFailure = 'repeated_failure';
    case CustomerRequested = 'customer_requested';
    case OutOfHours = 'out_of_hours';
    case BudgetExceeded = 'budget_exceeded';
    case TurnLimit = 'turn_limit';
    case Error = 'error';
}
