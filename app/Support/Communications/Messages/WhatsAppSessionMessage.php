<?php

declare(strict_types=1);

namespace App\Support\Communications\Messages;

/**
 * Free-form WhatsApp session text (only valid inside the 24h window).
 */
final readonly class WhatsAppSessionMessage
{
    public function __construct(
        public string $to,
        public string $body,
        public ?string $from = null,
    ) {}

    public function withSender(?string $from): self
    {
        return new self(
            to: $this->to,
            body: $this->body,
            from: $from ?? $this->from,
        );
    }
}
