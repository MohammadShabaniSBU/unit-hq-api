<?php

declare(strict_types=1);

namespace App\Support\Advertising\Results;

/**
 * cost is a decimal string (half-up, 2 places). currency is the ad account
 * currency captured at verify, never a sum across currencies.
 */
final readonly class SpendRow
{
    public function __construct(
        public string $externalCampaignId,
        public string $spendDate,
        public string $cost,
        public int $impressions,
        public int $clicks,
        public string $currency,
    ) {}
}
