<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\Enums\EntityType;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EntityTypeMorphMapTest extends TestCase
{
    #[Test]
    public function morph_overlapping_cases_match_the_morph_map_and_additions_are_explicit(): void
    {
        $morph = Relation::morphMap();
        $nonMorph = EntityType::nonMorphAdditions();

        $this->assertSame(
            [EntityType::Site, EntityType::UnitClass, EntityType::Discount],
            $nonMorph,
        );

        foreach (EntityType::cases() as $case) {
            if (in_array($case, $nonMorph, true)) {
                $this->assertArrayNotHasKey(
                    $case->value,
                    $morph,
                    "Non-morph EntityType [{$case->value}] must not collide with the morph map.",
                );

                continue;
            }

            $this->assertArrayHasKey(
                $case->value,
                $morph,
                "EntityType [{$case->value}] overlaps the morph map and must use the morph alias verbatim.",
            );
        }
    }
}
