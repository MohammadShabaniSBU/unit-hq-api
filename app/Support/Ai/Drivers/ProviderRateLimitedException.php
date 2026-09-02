<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use RuntimeException;

final class ProviderRateLimitedException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct('Provider rate limited: '.$key);
    }
}
