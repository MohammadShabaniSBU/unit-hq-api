<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ChargeType;
use InvalidArgumentException;

/**
 * Ageing bucket keys and rent/fees/other splits.
 * Definitions: docs/report-definitions.md — Ageing section.
 */
final class AgeingBuckets
{
    public const PROMISE_WINDOW_DAYS = 7;

    /** @var list<string> */
    public const KEYS = ['1-7', '8-14', '15-30', '31-60', '60+'];

    public static function fromDays(int $daysPastDue): string
    {
        if ($daysPastDue < 1) {
            throw new InvalidArgumentException('Days past due must be >= 1 for an ageing bucket.');
        }

        return match (true) {
            $daysPastDue <= 7 => '1-7',
            $daysPastDue <= 14 => '8-14',
            $daysPastDue <= 30 => '15-30',
            $daysPastDue <= 60 => '31-60',
            default => '60+',
        };
    }

    /**
     * @return 'rent'|'fees'|'other'
     */
    public static function amountGroup(ChargeType $type): string
    {
        return match ($type) {
            ChargeType::Rent, ChargeType::Insurance => 'rent',
            ChargeType::LateFee, ChargeType::LienFee => 'fees',
            default => 'other',
        };
    }
}
