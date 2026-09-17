<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Enums\LayoutFieldType;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\LayoutField;
use Database\Seeders\DefaultContactAttributeDefinitionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DefaultContactAttributeDefinitionsSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeds_contact_definitions_idempotently_with_select_options(): void
    {
        $this->seed(DefaultContactAttributeDefinitionsSeeder::class);
        $this->seed(DefaultContactAttributeDefinitionsSeeder::class);

        $definitions = AttributeDefinition::query()
            ->with('options')
            ->where('entity_type', AttributeEntityType::Contact)
            ->whereNull('archived_at')
            ->orderBy('display_order')
            ->get();

        $this->assertCount(8, $definitions);
        $this->assertSame(8, AttributeDefinition::query()->count());

        $this->assertFalse(
            AttributeDefinition::query()
                ->where('entity_type', AttributeEntityType::Contact)
                ->whereIn('key', DefaultContactAttributeDefinitionsSeeder::RETIRED_UNIT_REQUIREMENT_KEYS)
                ->whereNull('archived_at')
                ->exists()
        );

        $selects = $definitions->where('type', AttributeType::Select);
        $this->assertCount(3, $selects);

        foreach ($selects as $definition) {
            $this->assertGreaterThan(0, $definition->options->count());
        }
    }

    #[Test]
    public function archives_unit_requirement_keys_previously_seeded_on_contact(): void
    {
        $definition = AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => 'climate_control_required',
            'label' => 'Climate control required',
            'type' => AttributeType::Boolean,
            'group_name' => 'Storage needs',
            'display_order' => 3,
        ]);

        $group = AttributeGroup::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => 'contact_details',
            'label' => 'Contact details',
            'display_order' => 0,
            'is_system' => true,
        ]);

        $placement = LayoutField::query()->create([
            'group_id' => $group->id,
            'entity_type' => AttributeEntityType::Contact,
            'display_order' => 0,
            'field_type' => LayoutFieldType::Attribute,
            'native_field_key' => null,
            'attribute_definition_id' => $definition->id,
        ]);

        $this->seed(DefaultContactAttributeDefinitionsSeeder::class);

        $this->assertTrue($definition->fresh()->isArchived());
        $this->assertNull(LayoutField::query()->find($placement->id));
    }
}
