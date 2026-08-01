<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

use Carbon\CarbonImmutable;

/**
 * Derived provider-event keys for adapters that lack stable event ids.
 * Coarse by design: minute-bucketed so identical status retries within a
 * minute collapse; documented per adapter.
 */
final class DeliveryEventId
{
    public static function derive(
        string $providerMessageId,
        string $rawStatus,
        ?CarbonImmutable $occurredAt = null,
    ): string {
        $bucket = ($occurredAt ?? CarbonImmutable::now())->utc()->format('Y-m-d\TH:i');

        return hash('sha256', $providerMessageId.'|'.$rawStatus.'|'.$bucket);
    }
}
