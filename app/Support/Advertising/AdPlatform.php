<?php

declare(strict_types=1);

namespace App\Support\Advertising;

enum AdPlatform: string
{
    case GoogleAds = 'google_ads';
    case Meta = 'meta';
    case OpenAi = 'openai';

    public function isImplemented(): bool
    {
        return match ($this) {
            self::GoogleAds, self::Meta => true,
            self::OpenAi => false,
        };
    }
}
