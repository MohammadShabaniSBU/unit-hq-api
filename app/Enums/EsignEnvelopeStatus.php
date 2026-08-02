<?php

declare(strict_types=1);

namespace App\Enums;

enum EsignEnvelopeStatus: string
{
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Signed = 'signed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isLive(): bool
    {
        return $this === self::Sent || $this === self::Viewed;
    }
}
