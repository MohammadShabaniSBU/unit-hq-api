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
    case ReservationPending = 'reservation_pending';
    case ReservationExpired = 'reservation_expired';
    case ReservationCancelled = 'reservation_cancelled';

    public function targetCount(): int
    {
        return match ($this) {
            self::Browser => 220,
            self::QuickSigner => 140,
            self::ConsideredSigner => 240,
            self::SlowPayer => 35,
            self::SeriousDelinquent => 8,
            self::Churner => 80,
            self::UpsizerDownsizer => 12,
            self::ReservationPending => 32,
            self::ReservationExpired => 16,
            self::ReservationCancelled => 10,
        };
    }
}
