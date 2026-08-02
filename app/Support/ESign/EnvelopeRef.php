<?php

declare(strict_types=1);

namespace App\Support\ESign;

final class EnvelopeRef
{
    public function __construct(
        public readonly string $providerRef,
        public readonly ?string $signingUrl = null,
    ) {}
}
