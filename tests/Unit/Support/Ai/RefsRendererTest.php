<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\RefsRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RefsRendererTest extends TestCase
{
    #[Test]
    public function groups_and_sorts_by_type_then_id(): void
    {
        $line = RefsRenderer::render([
            EntityRef::of(EntityType::UnitClass, 12, 'Trastero 16 m² XL'),
            EntityRef::of(EntityType::Site, 4, 'Madrid Norte'),
            EntityRef::of(EntityType::UnitClass, 8, 'Trastero 12 m²'),
            EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
        ]);

        $this->assertSame(
            'Refs: site 1 = Madrid Centro; site 4 = Madrid Norte; unit_class 8 = Trastero 12 m²; unit_class 12 = Trastero 16 m² XL',
            $line,
        );
    }

    #[Test]
    public function type_order_follows_the_enum_declaration_not_the_alphabet(): void
    {
        $line = RefsRenderer::render([
            EntityRef::of(EntityType::Contact, 833, 'Ana Ruiz'),
            EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
            EntityRef::of(EntityType::Deal, 812, 'deal 812'),
        ]);

        $this->assertSame(
            'Refs: site 1 = Madrid Centro; contact 833 = Ana Ruiz; deal 812 = deal 812',
            $line,
        );
    }

    #[Test]
    public function empty_list_renders_nothing(): void
    {
        $this->assertSame('', RefsRenderer::render([]));
    }

    #[Test]
    public function heading_parameter_switches_the_prefix(): void
    {
        $refs = [EntityRef::of(EntityType::Site, 1, 'Madrid Centro')];

        $this->assertSame('Refs: site 1 = Madrid Centro', RefsRenderer::render($refs));
        $this->assertSame('Candidates: site 1 = Madrid Centro', RefsRenderer::render($refs, 'Candidates'));
    }

    #[Test]
    public function label_is_verbatim_and_context_is_omitted(): void
    {
        $line = RefsRenderer::render([
            EntityRef::of(EntityType::UnitClass, 12, 'Trastero 16 m² XL', 'Madrid Centro'),
        ]);

        $this->assertSame('Refs: unit_class 12 = Trastero 16 m² XL', $line);
        $this->assertStringNotContainsString('Madrid Centro', $line);
    }

    #[Test]
    public function repeated_type_and_id_pairs_are_listed_once(): void
    {
        $line = RefsRenderer::render([
            EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
            EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
        ]);

        $this->assertSame('Refs: site 1 = Madrid Centro', $line);
    }
}
