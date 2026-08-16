<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

final readonly class DraftToken
{
    public const Money = 'money';

    public const Percent = 'percent';

    public const Date = 'date';

    public const Identifier = 'identifier';

    public const Number = 'number';

    public function __construct(
        public string $type,
        public string $raw,
        public string $normalized,
        public ?string $currency = null,
    ) {}
}
