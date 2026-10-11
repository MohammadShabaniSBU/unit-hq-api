<?php

declare(strict_types=1);

namespace App\Support\Advertising\Results;

final readonly class DeliveryResult
{
    public function __construct(
        public string $dedupeId,
        public bool $ok,
        public bool $retryable,
        public ?string $error = null,
        public ?string $providerRequestId = null,
    ) {}
}
