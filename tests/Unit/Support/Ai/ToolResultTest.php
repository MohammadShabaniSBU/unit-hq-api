<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolError;
use App\Support\Ai\Tools\ToolResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ToolResultTest extends TestCase
{
    #[Test]
    public function ok_result_with_entities_appends_a_refs_line(): void
    {
        $result = ToolResult::ok(
            ['unit_class_id' => 12, 'available' => 3],
            '3 units available in Trastero 16 m² XL (16.00 m²) at Madrid Centro as of now.',
            new FactBag,
            entities: [
                EntityRef::of(EntityType::UnitClass, 12, 'Trastero 16 m² XL'),
                EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
            ],
        );

        $this->assertSame(
            "3 units available in Trastero 16 m² XL (16.00 m²) at Madrid Centro as of now.\n"
            .'Refs: site 1 = Madrid Centro; unit_class 12 = Trastero 16 m² XL',
            $result->modelText(),
        );
    }

    #[Test]
    public function ok_result_without_entities_is_display_unchanged(): void
    {
        $result = ToolResult::ok(
            ['amount' => '84.70'],
            '€84,70 (incl. 21% IVA)',
            new FactBag,
        );

        $this->assertSame('€84,70 (incl. 21% IVA)', $result->modelText());
        $this->assertStringNotContainsString('Refs:', $result->modelText());
    }

    #[Test]
    public function error_result_renders_candidates_and_never_refs(): void
    {
        $result = ToolResult::fail(ToolError::siteUnresolved(
            'no site matches 28001',
            [
                EntityRef::of(EntityType::Site, 4, 'Madrid Norte'),
                EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
            ],
        ));

        $text = $result->modelText();

        $this->assertStringContainsString(
            'Candidates: site 1 = Madrid Centro; site 4 = Madrid Norte',
            $text,
        );
        $this->assertStringNotContainsString('Refs:', $text);
        $this->assertStringStartsWith($result->display, $text);
    }

    #[Test]
    public function error_result_without_candidates_is_display_unchanged(): void
    {
        $result = ToolResult::error('the pricing service is unavailable');

        $this->assertSame($result->display, $result->modelText());
        $this->assertStringNotContainsString('Candidates:', $result->modelText());
    }

    #[Test]
    public function entities_on_a_failed_result_do_not_render_as_refs(): void
    {
        $result = ToolResult::fail(
            ToolError::notFound('nothing here'),
            entities: [EntityRef::of(EntityType::Site, 1, 'Madrid Centro')],
        );

        $this->assertStringNotContainsString('Refs:', $result->modelText());
        $this->assertStringNotContainsString('site 1', $result->modelText());
    }
}
