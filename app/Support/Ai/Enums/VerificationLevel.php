<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum VerificationLevel: string
{
    case Anonymous = 'anonymous';
    case ChannelAsserted = 'channel_asserted';
    case Verified = 'verified';

    public function rank(): int
    {
        return match ($this) {
            self::Anonymous => 0,
            self::ChannelAsserted => 1,
            self::Verified => 2,
        };
    }

    public function satisfies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
