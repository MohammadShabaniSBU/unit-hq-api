<?php

declare(strict_types=1);

namespace App\Support\ESign;

final class SignedResult
{
    public function __construct(
        public readonly string $pdfBytes,
        public readonly ?string $certificateBytes = null,
    ) {}
}
