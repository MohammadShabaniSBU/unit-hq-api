<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Enums\CommunicationProviderType;

final class CommunicationProviderResolver
{
    public static function resolve(CommunicationProviderType $type): CommunicationProvider
    {
        return match ($type) {
            CommunicationProviderType::Brevo => new BrevoAdapter,
            CommunicationProviderType::Snich => new SnichAdapter,
        };
    }
}
