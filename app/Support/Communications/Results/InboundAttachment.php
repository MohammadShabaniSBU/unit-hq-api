<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

/**
 * Parsed inbound attachment before persistence / size-cap checks.
 */
final readonly class InboundAttachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $contentBase64,
    ) {}
}
