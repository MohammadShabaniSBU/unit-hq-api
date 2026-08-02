<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

/**
 * Provider-authoritative WhatsApp template approval state.
 */
final readonly class TemplateStatusSnapshot
{
    public function __construct(
        public string $providerTemplateId,
        public string $status,
        public ?string $name = null,
        public ?string $language = null,
        public ?string $rejectionReason = null,
    ) {}
}
