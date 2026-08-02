<?php

declare(strict_types=1);

namespace App\Support\Access;

/**
 * Provider-side grant reference. PIN is present only for pin-mode grants —
 * store encrypted on the grant row; never put it in logs.
 */
final readonly class GrantRef
{
    public function __construct(
        public string $providerGrantId,
        public ?string $pin = null,
        public ?string $credentialRef = null,
    ) {}
}
