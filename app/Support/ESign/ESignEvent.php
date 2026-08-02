<?php

declare(strict_types=1);

namespace App\Support\ESign;

final class ESignEvent
{
    public const TYPE_SENT = 'sent';

    public const TYPE_VIEWED = 'viewed';

    public const TYPE_SIGNED = 'signed';

    public const TYPE_DECLINED = 'declined';

    public const TYPE_EXPIRED = 'expired';

    public const TYPE_BOUNCED = 'bounced';

    public const TYPE_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const KNOWN_TYPES = [
        self::TYPE_SENT,
        self::TYPE_VIEWED,
        self::TYPE_SIGNED,
        self::TYPE_DECLINED,
        self::TYPE_EXPIRED,
        self::TYPE_BOUNCED,
    ];

    /**
     * @param  array{name?: string, email?: string}|null  $signer
     */
    public function __construct(
        public readonly string $providerEventId,
        public readonly string $envelopeRef,
        public readonly string $type,
        public readonly ?\DateTimeInterface $occurredAt = null,
        public readonly ?array $signer = null,
        public readonly ?string $declineReason = null,
    ) {}

    public function isKnown(): bool
    {
        return in_array($this->type, self::KNOWN_TYPES, true);
    }
}
