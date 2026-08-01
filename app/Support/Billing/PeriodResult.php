<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * Outcome of generating one due period for a contract (charges + invoice).
 * Aggregated by the run engine into a billing_run_items row.
 */
final readonly class PeriodResult
{
    /**
     * @param  list<int>  $invoiceIds
     */
    public function __construct(
        public int $periodsBilled = 1,
        public string $amountTotal = '0.00',
        public ?string $currency = null,
        public array $invoiceIds = [],
        public ?string $skipDetail = null,
    ) {}

    public static function empty(): self
    {
        return new self(
            periodsBilled: 1,
            amountTotal: '0.00',
            currency: null,
            invoiceIds: [],
        );
    }

    public function isSkip(): bool
    {
        return $this->skipDetail !== null;
    }
}
