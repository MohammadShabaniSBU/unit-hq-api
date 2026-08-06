<?php

declare(strict_types=1);

namespace App\Support\Insights\Exceptions;

use RuntimeException;

/**
 * Provider-side or resolve-time embed failure (machine reason for the panel).
 */
final class EmbedUrlException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly string $reasonKey,
        public readonly int $statusCode = 422,
        public readonly array $errors = [],
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reasonKey);
    }

    public static function credentialsUnreadable(): self
    {
        return new self('credentials_unreadable', 409);
    }

    public static function unfilledPlaceholder(string $placeholder): self
    {
        return new self(
            'param_unresolved',
            422,
            ['param' => $placeholder],
            'Unfilled iframe placeholder: '.$placeholder,
        );
    }

    public static function siteRequired(): self
    {
        return new self('site_required', 422);
    }

    public static function paramUnresolved(string $paramName): self
    {
        return new self('param_unresolved', 422, ['param' => $paramName]);
    }
}
