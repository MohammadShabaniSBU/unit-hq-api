<?php

declare(strict_types=1);

namespace App\Support\Communications\Results;

/**
 * Provider acknowledgement after template submission.
 */
final readonly class ProviderTemplateRef
{
    public function __construct(
        public string $providerTemplateId,
        public string $status = 'submitted',
    ) {}
}
