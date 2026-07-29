<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

final readonly class WebhookRegistration
{
    public function __construct(
        public string $endpointId,
        public ?string $signingSecret = null,
    ) {}
}
