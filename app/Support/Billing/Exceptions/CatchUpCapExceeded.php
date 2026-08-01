<?php

declare(strict_types=1);

namespace App\Support\Billing\Exceptions;

final class CatchUpCapExceeded extends BillingException
{
    public function __construct(
        public readonly int $count,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Catch-up period cap exceeded ({$count}).");
    }
}
