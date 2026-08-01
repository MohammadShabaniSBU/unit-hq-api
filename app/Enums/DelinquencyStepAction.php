<?php

declare(strict_types=1);

namespace App\Enums;

enum DelinquencyStepAction: string
{
    case AssessLateFee = 'assess_late_fee';
    case PlaceOverlock = 'place_overlock';
    case RecordNotice = 'record_notice';
    case CreateTask = 'create_task';
    case RevokeAccess = 'revoke_access';
    /** Cure-trigger step row — not a policy ladder action. */
    case Cure = 'cure';
    /** Release-overlock step — timeline only, not a policy ladder action. */
    case ReleaseOverlock = 'release_overlock';

    public static function fromPolicyAction(DelinquencyPolicyAction $action): self
    {
        return self::from($action->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
