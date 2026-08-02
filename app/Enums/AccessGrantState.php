<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessGrantState: string
{
    case Applying = 'applying';
    case Applied = 'applied';
    case Revoking = 'revoking';
    case Revoked = 'revoked';
    case Failed = 'failed';

    public function isLive(): bool
    {
        return $this === self::Applying || $this === self::Applied;
    }
}
