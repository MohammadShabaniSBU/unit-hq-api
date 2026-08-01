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
        public ?int $messageId = null,
        public ?int $interactionId = null,
    ) {}

    public function withAccountId(int $accountId): self
    {
        return new self(
            providerMessageId: $this->providerMessageId,
            provider: $this->provider,
            accountId: $accountId,
            raw: $this->raw,
            messageId: $this->messageId,
            interactionId: $this->interactionId,
        );
    }

    public function withStoreIds(?int $messageId, ?int $interactionId): self
    {
        return new self(
            providerMessageId: $this->providerMessageId,
            provider: $this->provider,
            accountId: $this->accountId,
            raw: $this->raw,
            messageId: $messageId,
            interactionId: $interactionId,
        );
    }
}
