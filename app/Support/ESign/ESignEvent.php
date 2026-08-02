<?php

declare(strict_types=1);

namespace App\Support\ESign;

final class ESignEvent
{
    /**
     * @param  array{name?: string, email?: string}|null  $signer
     */
    public function __construct(
        public readonly string $envelopeRef,
        public readonly string $type,
        public readonly ?\DateTimeInterface $occurredAt = null,
        public readonly ?array $signer = null,
        public readonly ?string $declineReason = null,
    ) {}
}
