<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\AccessEventType;
use DateTimeInterface;

/**
 * Normalized access webhook event from a provider adapter.
 */
final readonly class AccessWebhookPayload
{
    public const TYPE_UNKNOWN = 'unknown';

    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $providerPointId = null,
        public ?string $providerGrantId = null,
        public ?string $providerCredentialRef = null,
        public ?DateTimeInterface $occurredAt = null,
    ) {}

    public function isKnown(): bool
    {
        return in_array($this->eventType, [
            AccessEventType::Granted->value,
            AccessEventType::Denied->value,
        ], true);
    }
}
