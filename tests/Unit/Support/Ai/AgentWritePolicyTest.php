<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Models\AgentWritePolicy;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\SpyTool;
use Tests\TestCase;

class AgentWritePolicyTest extends TestCase
{
    #[Test]
    public function min_verification_raises_the_tool_floor(): void
    {
        $tool = new SpyTool(required: VerificationLevel::Anonymous);
        $policy = new AgentWritePolicy([
            'mode' => WritePolicyMode::Commit,
            'min_verification' => VerificationLevel::Verified,
        ]);

        $this->assertSame(VerificationLevel::Verified, $policy->effectiveVerification($tool));
    }

    #[Test]
    public function min_verification_cannot_lower_the_tool_floor(): void
    {
        $tool = new SpyTool(required: VerificationLevel::Verified);
        $policy = new AgentWritePolicy([
            'mode' => WritePolicyMode::Commit,
            'min_verification' => VerificationLevel::Anonymous,
        ]);

        $this->assertSame(VerificationLevel::Verified, $policy->effectiveVerification($tool));
        $this->assertSame(VerificationLevel::Anonymous, $policy->min_verification);
    }

    #[Test]
    public function null_min_verification_keeps_the_tool_floor(): void
    {
        $tool = new SpyTool(required: VerificationLevel::ChannelAsserted);
        $policy = new AgentWritePolicy([
            'mode' => WritePolicyMode::Commit,
            'min_verification' => null,
        ]);

        $this->assertSame(VerificationLevel::ChannelAsserted, $policy->effectiveVerification($tool));
    }

    #[Test]
    public function allows_is_false_only_for_off(): void
    {
        $off = new AgentWritePolicy(['mode' => WritePolicyMode::Off]);
        $commit = new AgentWritePolicy(['mode' => WritePolicyMode::Commit]);
        $propose = new AgentWritePolicy(['mode' => WritePolicyMode::Propose]);

        $this->assertFalse($off->allows());
        $this->assertTrue($commit->allows());
        $this->assertTrue($propose->allows());
    }
}
