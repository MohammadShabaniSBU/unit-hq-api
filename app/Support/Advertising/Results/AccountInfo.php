<?php

declare(strict_types=1);

namespace App\Support\Advertising\Results;

final readonly class AccountInfo
{
    public function __construct(
        public string $currency,
        public string $timezone,
        public ?string $name = null,
    ) {}
}
