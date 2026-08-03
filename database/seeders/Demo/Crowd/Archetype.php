<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd;

/**
 * Crowd cohorts. Counts are generation targets — matrix bands tolerate variance.
 */
enum Archetype: string
{
    case Browser = 'browser';
    case QuickSigner = 'quick_signer';
    case ConsideredSigner = 'considered_signer';
    case SlowPayer = 'slow_payer';
    case SeriousDelinquent = 'serious_delinquent';
    case Churner = 'churner';
    case UpsizerDownsizer = 'upsizer_downsizer';

    public function targetCount(): int
    {
        return match ($this) {
            self::Browser => 110,
            self::QuickSigner => 75,
            self::ConsideredSigner => 40,
            self::SlowPayer => 25,
            self::SeriousDelinquent => 8,
            self::Churner => 45,
            self::UpsizerDownsizer => 10,
        };
    }
}
