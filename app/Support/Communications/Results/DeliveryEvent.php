<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

use Carbon\CarbonImmutable;

final readonly class DeliveryEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $providerMessageId,
        public DeliveryStatus $status,
        public string $rawStatus,
        public ?CarbonImmutable $occurredAt,
        public ?string $recipient,
        public ?string $reason,
        public array $raw,
    ) {}
}
