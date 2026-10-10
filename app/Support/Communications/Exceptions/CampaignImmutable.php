<?php

declare(strict_types=1);

namespace App\Support\Communications\Exceptions;

use RuntimeException;

final class CampaignImmutable extends RuntimeException
{
    public static function audience(): self
    {
        return new self('campaign_audience_immutable');
    }

    public static function pinnedSend(): self
    {
        return new self('campaign_send_pinned');
    }
}
