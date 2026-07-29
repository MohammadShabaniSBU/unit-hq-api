<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

use App\Support\Communications\Provider;

final readonly class SendResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $providerMessageId,
        public Provider $provider,
        public int $accountId,
        public array $raw,
    ) {}

    public function withAccountId(int $accountId): self
    {
        return new self(
            providerMessageId: $this->providerMessageId,
            provider: $this->provider,
            accountId: $accountId,
            raw: $this->raw,
        );
    }
}
