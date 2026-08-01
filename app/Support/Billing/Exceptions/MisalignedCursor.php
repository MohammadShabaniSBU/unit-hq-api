<?php

declare(strict_types=1);

namespace App\Support\Billing\Exceptions;

final class MisalignedCursor extends BillingException
{
    public static function for(string $cursor): self
    {
        return new self("Billing cursor {$cursor} is not a valid period boundary for this contract.");
    }
}
