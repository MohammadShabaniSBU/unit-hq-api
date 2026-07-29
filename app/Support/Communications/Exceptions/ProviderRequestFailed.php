<?php

declare(strict_types=1);

namespace App\Support\Communications\Exceptions;

use App\Support\Communications\Provider;
use Illuminate\Http\Client\Response;

final class ProviderRequestFailed extends CommunicationException
{
    public function __construct(
        public readonly Provider $provider,
        public readonly int $httpStatus,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Provider $provider, Response $response, ?string $context = null): self
    {
        $prefix = $context ?? $provider->label();

        return new self(
            $provider,
            $response->status(),
            sprintf('%s request failed (%d): %s', $prefix, $response->status(), $response->body()),
        );
    }
}
