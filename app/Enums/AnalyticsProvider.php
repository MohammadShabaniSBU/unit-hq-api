<?php

declare(strict_types=1);

namespace App\Enums;

enum AnalyticsProvider: string
{
    case Metabase = 'metabase';
    case Iframe = 'iframe';

    public function label(): string
    {
        return match ($this) {
            self::Metabase => 'Metabase',
            self::Iframe => 'Generic iframe',
        };
    }
}
