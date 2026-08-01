<?php

declare(strict_types=1);

namespace App\Support\Billing\Exceptions;

/**
 * Per-contract billing failure with a stable detail key for billing_run_items.
 */
final class BillingRunFailure extends BillingException
{
    public function __construct(
        public readonly string $detail,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $detail);
    }
}
