<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

/**
 * Server-resolved catalogue quote for one (site, class) option.
 * Null fields mean the class was never quoted in this conversation.
 */
final readonly class ContinuityDecision
{
    public function __construct(
        public ?int $priceId,
        public ?int $taxRateId,
        public ?int $invocationId,
    ) {}
}
