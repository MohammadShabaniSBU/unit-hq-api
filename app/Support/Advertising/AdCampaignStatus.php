<?php

declare(strict_types=1);

namespace App\Support\Advertising;

enum AdCampaignStatus: string
{
    case Enabled = 'enabled';
    case Paused = 'paused';
    case Removed = 'removed';

    /**
     * Google ENABLED / Meta ACTIVE become enabled. REMOVED, DELETED, and
     * ARCHIVED become removed. Every other platform status is paused.
     */
    public static function fromPlatform(string $raw): self
    {
        $upper = strtoupper($raw);

        if (in_array($upper, ['ENABLED', 'ACTIVE'], true)) {
            return self::Enabled;
        }

        if (in_array($upper, ['REMOVED', 'DELETED', 'ARCHIVED'], true)) {
            return self::Removed;
        }

        return self::Paused;
    }
}
