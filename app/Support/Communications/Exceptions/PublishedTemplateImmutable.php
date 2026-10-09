<?php

declare(strict_types=1);

namespace App\Support\Communications\Exceptions;

use RuntimeException;

final class PublishedTemplateImmutable extends RuntimeException
{
    public static function variant(): self
    {
        return new self('Published template variants are immutable.');
    }

    public static function version(): self
    {
        return new self('Published template versions are immutable.');
    }
}
