<?php

declare(strict_types=1);

namespace App\Enums;

enum DelinquencyPolicyAction: string
{
    case AssessLateFee = 'assess_late_fee';
    case PlaceOverlock = 'place_overlock';
    case RecordNotice = 'record_notice';
    case CreateTask = 'create_task';
    /** Reserved for S16 access-control integration — rejected on create/update. */
    case RevokeAccess = 'revoke_access';

    public function isReserved(): bool
    {
        return $this === self::RevokeAccess;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
