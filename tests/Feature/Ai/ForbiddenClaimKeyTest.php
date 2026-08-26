<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\Enums\ForbiddenClaimKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForbiddenClaimKeyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_enum_has_exactly_two_cases(): void
    {
        $this->assertSame(
            [
                ForbiddenClaimKey::AvailabilityGuarantee,
                ForbiddenClaimKey::CapacityGuidance,
            ],
            ForbiddenClaimKey::cases(),
        );
    }

    #[Test]
    public function the_en_availability_guarantee_group_includes_the_original_and_commitment_forms(): void
    {
        $phrases = config('ai-handoff.forbidden_claims.en.availability_guarantee');
        $this->assertIsArray($phrases);
        foreach ([
            "i've held it for you",
            'i have held it for you',
            "it's reserved",
            'it is reserved',
            'i have reserved',
            "i've reserved",
            'move forward with a reservation',
            "I'll create a reservation",
            'reserved for you',
            'held for you',
        ] as $phrase) {
            $this->assertContains($phrase, $phrases);
        }
    }

    #[Test]
    public function the_en_capacity_guidance_group_includes_the_trace_30_wording(): void
    {
        $phrases = config('ai-handoff.forbidden_claims.en.capacity_guidance');
        $this->assertIsArray($phrases);
        $this->assertContains('should work well', $phrases);
        $this->assertContains('will fit', $phrases);
    }
}
