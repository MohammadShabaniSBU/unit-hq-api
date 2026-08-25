<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

/**
 * Claims a tool may license for the current turn only.
 *
 * PaymentConfirmation, FeeWaiver, AccessGrant, LegalAdvice and ContractMutation
 * are absent on purpose and stay unlicensable, by any tool, in any sprint.
 */
enum ForbiddenClaimKey: string
{
    case AvailabilityGuarantee = 'availability_guarantee';
    case CapacityGuidance = 'capacity_guidance';
}
