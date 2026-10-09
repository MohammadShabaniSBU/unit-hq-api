<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use RuntimeException;

/**
 * A consent write broke a ledger rule. errorKey maps to errors.consent.{key}.
 */
final class ConsentRuleException extends RuntimeException
{
    public function __construct(public readonly string $errorKey)
    {
        parent::__construct($errorKey);
    }
}
