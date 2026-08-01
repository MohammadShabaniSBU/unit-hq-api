<?php

declare(strict_types=1);

namespace App\Support\Payments;

use RuntimeException;

/**
 * Thrown when a contract's legal entity has no active connected payment
 * provider account. Surfaces as 422 from later payment endpoints.
 */
final class PaymentsNotConfigured extends RuntimeException
{
    public function __construct(
        public readonly string $legalEntityName,
        public readonly ?int $legalEntityId = null,
    ) {
        parent::__construct(
            "Payments are not configured for legal entity \"{$legalEntityName}\"."
        );
    }
}
