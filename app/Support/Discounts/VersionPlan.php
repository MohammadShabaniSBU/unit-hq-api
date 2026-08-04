<?php

declare(strict_types=1);

namespace App\Support\Discounts;

/**
 * Compiled discount schedule: half-open version windows [{from, to|null, amount}].
 *
 * @phpstan-type Segment array{from: string, to: string|null, amount: string}
 */
final readonly class VersionPlan
{
    /**
     * @param  array<int, Segment>  $segments
     * @param  array{min_commitment_weeks: int, free_weeks: int}|null  $resolvedTier
     */
    public function __construct(
        public array $segments,
        public bool $noop = false,
        public ?array $resolvedTier = null,
    ) {}

    /**
     * @return array{
     *     noop: bool,
     *     resolved_tier: array{min_commitment_weeks: int, free_weeks: int}|null,
     *     segments: array<int, array{from: string, to: string|null, amount: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'noop' => $this->noop,
            'resolved_tier' => $this->resolvedTier,
            'segments' => $this->segments,
        ];
    }

    public function firstAmount(): ?string
    {
        return $this->segments[0]['amount'] ?? null;
    }
}
