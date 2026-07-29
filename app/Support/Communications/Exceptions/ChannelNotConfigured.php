<?php

declare(strict_types=1);

namespace App\Support\Communications\Exceptions;

use App\Support\Communications\Channel;

final class ChannelNotConfigured extends CommunicationException
{
    public static function for(Channel $channel): self
    {
        return new self("No active {$channel->value} communication account is configured.");
    }

    public static function siteArchived(): self
    {
        return new self('Cannot send communications for an archived site.');
    }
}
