<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\AgentChannelLimits;
use App\Support\Ai\Enums\AgentChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentChannelLimitsTest extends TestCase
{
    #[Test]
    public function voice_overrides_timeout_and_redraft_sms_keeps_defaults(): void
    {
        $this->assertSame(8_000, AgentChannelLimits::turnTimeoutMs(AgentChannel::Voice));
        $this->assertSame(1, AgentChannelLimits::maxRedraftAttempts(AgentChannel::Voice));
        $this->assertSame(60_000, AgentChannelLimits::turnTimeoutMs(AgentChannel::Sms));
        $this->assertSame(2, AgentChannelLimits::maxRedraftAttempts(AgentChannel::Sms));
        $this->assertSame(60_000, AgentChannelLimits::turnTimeoutMs(AgentChannel::Email));
    }

    #[Test]
    public function provider_rates_share_one_config_shape(): void
    {
        $this->assertSame(30, AgentChannelLimits::providerRatePerMinute(AgentChannel::Voice));
        $this->assertSame(20, AgentChannelLimits::providerRatePerMinute(AgentChannel::Sms));
        $this->assertSame('ai-provider:voice', AgentChannelLimits::providerLimiterKey(AgentChannel::Voice));
        $this->assertSame('ai-provider:batch', AgentChannelLimits::providerLimiterKey(AgentChannel::Email));
    }
}
