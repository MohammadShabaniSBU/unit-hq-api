<?php

declare(strict_types=1);

namespace App\Support\Templates\Assist;

use RuntimeException;

final class AssistContextException extends RuntimeException
{
    public function __construct(public readonly string $errorKey)
    {
        parent::__construct($errorKey);
    }

    public static function channelUnsupported(): self
    {
        return new self('assist.channel_unsupported');
    }

    public static function localeMissing(): self
    {
        return new self('assist.locale_missing');
    }
}
