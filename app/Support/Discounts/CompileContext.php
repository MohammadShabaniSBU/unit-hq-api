<?php

declare(strict_types=1);

namespace App\Support\Discounts;

/**
 * Inputs for {@see DiscountCompiler::compile}. Pure value object — no DB.
 */
final readonly class CompileContext
{
    public function __construct(
        public string $listAmount,
        public string $currency,
        public string $interval,
        public int $intervalCount,
        public string $anchorDate,
        public ?int $commitmentWeeks,
    ) {}

    public function periodDays(): int
    {
        $count = max(1, $this->intervalCount);

        return match ($this->interval) {
            'day' => $count,
            'week' => $count * 7,
            default => $count * 30,
        };
    }
}
