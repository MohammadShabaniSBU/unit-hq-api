<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\Enums\VerificationLevel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerificationLevelTest extends TestCase
{
    #[Test]
    public function rank_orders_anonymous_below_channel_asserted_below_verified(): void
    {
        $this->assertSame(0, VerificationLevel::Anonymous->rank());
        $this->assertSame(1, VerificationLevel::ChannelAsserted->rank());
        $this->assertSame(2, VerificationLevel::Verified->rank());
    }

    #[Test]
    public function satisfies_is_true_when_rank_is_greater_or_equal(): void
    {
        $this->assertTrue(VerificationLevel::Anonymous->satisfies(VerificationLevel::Anonymous));
        $this->assertTrue(VerificationLevel::ChannelAsserted->satisfies(VerificationLevel::Anonymous));
        $this->assertTrue(VerificationLevel::ChannelAsserted->satisfies(VerificationLevel::ChannelAsserted));
        $this->assertTrue(VerificationLevel::Verified->satisfies(VerificationLevel::Anonymous));
        $this->assertTrue(VerificationLevel::Verified->satisfies(VerificationLevel::ChannelAsserted));
        $this->assertTrue(VerificationLevel::Verified->satisfies(VerificationLevel::Verified));
    }

    #[Test]
    public function satisfies_is_false_when_rank_is_lower(): void
    {
        $this->assertFalse(VerificationLevel::Anonymous->satisfies(VerificationLevel::ChannelAsserted));
        $this->assertFalse(VerificationLevel::Anonymous->satisfies(VerificationLevel::Verified));
        $this->assertFalse(VerificationLevel::ChannelAsserted->satisfies(VerificationLevel::Verified));
    }
}
