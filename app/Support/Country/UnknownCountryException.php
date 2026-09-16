<?php

declare(strict_types=1);

namespace App\Support\Country;

use RuntimeException;

final class UnknownCountryException extends RuntimeException
{
    public static function missing(): self
    {
        return new self(
            'KEEVARIS_COUNTRY is required (ISO 3166-1 alpha-2). Set it in the environment to a supported profile: ES, FR, GB.',
        );
    }

    public static function unknown(string $code): self
    {
        return new self(
            "KEEVARIS_COUNTRY={$code} has no CountryProfile. Supported profiles: ES, FR, GB.",
        );
    }
}
