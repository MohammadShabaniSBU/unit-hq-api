<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use RuntimeException;

/**
 * Normalised ad-platform failure. retryable is true only for 429 and 5xx
 * (and connection failures). The message never includes a credential.
 */
final class AdPlatformException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $status = null,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
