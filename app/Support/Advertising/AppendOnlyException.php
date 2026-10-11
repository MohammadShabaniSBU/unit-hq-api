<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use RuntimeException;

final class AppendOnlyException extends RuntimeException
{
    public static function immutable(string $subject): self
    {
        return new self($subject.'_immutable');
    }

    public static function status(): self
    {
        return new self('ad_conversion_delivery_status');
    }
}
