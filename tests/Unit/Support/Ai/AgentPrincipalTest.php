<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentPrincipalTest extends TestCase
{
    #[Test]
    public function owns_contact_is_identity_only(): void
    {
        $principal = AgentPrincipal::verified(10, 1, 'en');

        $this->assertTrue($principal->ownsContact(10));
        $this->assertFalse($principal->ownsContact(11));
    }

    #[Test]
    public function anonymous_never_owns_a_contact(): void
    {
        $principal = AgentPrincipal::anonymous(1, 'en');

        $this->assertFalse($principal->ownsContact(1));
        $this->assertNull($principal->contactId);
    }

    #[Test]
    public function employee_never_owns_a_contact(): void
    {
        $principal = AgentPrincipal::employee(5, 1, 'en');

        $this->assertFalse($principal->ownsContact(5));
        $this->assertNull($principal->contactId);
    }

    #[Test]
    public function channel_asserted_owns_only_its_contact(): void
    {
        $principal = AgentPrincipal::channelAsserted(7, null, 'es');

        $this->assertTrue($principal->ownsContact(7));
        $this->assertFalse($principal->ownsContact(8));
    }

    #[Test]
    public function verification_satisfies_both_directions(): void
    {
        $anonymous = AgentPrincipal::anonymous(null, 'en');
        $asserted = AgentPrincipal::channelAsserted(1, null, 'en');
        $verified = AgentPrincipal::verified(1, null, 'en');

        $this->assertTrue($anonymous->verification->satisfies(VerificationLevel::Anonymous));
        $this->assertFalse($anonymous->verification->satisfies(VerificationLevel::ChannelAsserted));
        $this->assertFalse($anonymous->verification->satisfies(VerificationLevel::Verified));

        $this->assertTrue($asserted->verification->satisfies(VerificationLevel::Anonymous));
        $this->assertTrue($asserted->verification->satisfies(VerificationLevel::ChannelAsserted));
        $this->assertFalse($asserted->verification->satisfies(VerificationLevel::Verified));

        $this->assertTrue($verified->verification->satisfies(VerificationLevel::Anonymous));
        $this->assertTrue($verified->verification->satisfies(VerificationLevel::ChannelAsserted));
        $this->assertTrue($verified->verification->satisfies(VerificationLevel::Verified));
    }
}
