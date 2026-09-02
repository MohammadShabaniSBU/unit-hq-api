<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Enums\AgentChannel;

final class AgentChannelLimits
{
    public static function turnTimeoutMs(AgentChannel $channel): int
    {
        $override = config('agents.channel.'.$channel->value.'.turn_timeout_ms');
        if (is_numeric($override)) {
            return max(0, (int) $override);
        }

        return max(0, (int) config('agents.turn_timeout_ms', 60_000));
    }

    public static function maxRedraftAttempts(AgentChannel $channel): int
    {
        $override = config('agents.channel.'.$channel->value.'.max_redraft_attempts');
        if (is_numeric($override)) {
            return max(0, (int) $override);
        }

        return max(0, (int) config('agents.channel.sms.max_redraft_attempts', 2));
    }

    public static function providerRatePerMinute(AgentChannel $channel): int
    {
        $lane = $channel === AgentChannel::Voice ? 'voice' : 'batch';

        return max(1, (int) config('agents.provider_rate_per_minute.'.$lane));
    }

    public static function providerLimiterKey(AgentChannel $channel): string
    {
        return $channel === AgentChannel::Voice ? 'ai-provider:voice' : 'ai-provider:batch';
    }
}
