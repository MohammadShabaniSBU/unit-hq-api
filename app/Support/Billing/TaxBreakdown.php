<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * Exclusive-tax breakdown of a charge. All three fields are decimal strings,
 * NUMERIC(10,2) — never floats.
 */
final readonly class TaxBreakdown
{
    public function __construct(
        public string $net,
        public string $tax,
        public string $gross,
    ) {}
}
