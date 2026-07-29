<?php

declare(strict_types=1);

namespace App\Support\Communications\Exceptions;

final class UnsupportedCapability extends CommunicationException
{
    public static function for(string $capabilityLabel): self
    {
        return new self("The resolved provider does not support {$capabilityLabel}.");
    }
}
