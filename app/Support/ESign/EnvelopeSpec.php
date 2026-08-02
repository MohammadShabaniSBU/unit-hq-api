<?php

declare(strict_types=1);

namespace App\Support\ESign;

/**
 * @phpstan-type SignerSpec array{name: string, email: string}
 */
final class EnvelopeSpec
{
    /**
     * @param  SignerSpec  $signer
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $pdfBytes,
        public readonly string $title,
        public readonly array $signer,
        public readonly ?\DateTimeInterface $expiresAt = null,
        public readonly array $metadata = [],
    ) {}
}
