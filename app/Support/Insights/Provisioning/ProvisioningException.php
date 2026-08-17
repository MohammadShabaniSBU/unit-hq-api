<?php

declare(strict_types=1);

namespace App\Support\Insights\Provisioning;

use RuntimeException;

/**
 * Operator-readable provisioning failure. Messages carry the provider's
 * text plus status code. Never include request bodies or credentials
 * (invariant 51).
 */
final class ProvisioningException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonKey,
        public readonly int $httpStatus = 0,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reasonKey);
    }

    public static function credentialsUnreadable(): self
    {
        return new self(
            'credentials_unreadable',
            401,
            'Analytics credentials could not be read.',
        );
    }

    public static function fromProvider(string $text, int $status): self
    {
        $trimmed = trim($text);
        $message = $trimmed !== ''
            ? $trimmed.' (HTTP '.$status.')'
            : 'Metabase request failed (HTTP '.$status.').';

        $reason = ($status === 401 || $status === 403)
            ? 'credentials_unreadable'
            : 'provider_error';

        return new self($reason, $status, $message);
    }

    public static function unreachable(string $detail = ''): self
    {
        $suffix = $detail !== '' ? ': '.$detail : '.';

        return new self('provider_unreachable', 0, 'Could not reach the Metabase instance'.$suffix);
    }
}
