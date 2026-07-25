<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttributeEntityType;
use App\Enums\LayoutFieldType;
use App\Models\AttributeGroup;
use App\Models\LayoutField;
use App\Support\Layout\NativeFields;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Inserts system overview cards + default native layout fields per entity.
 *
 * Idempotent: skips an entity when its default system group already exists.
 *
 *   php artisan db:seed --class=DefaultAttributeLayoutSeeder
 */
class DefaultAttributeLayoutSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (AttributeEntityType::cases() as $entityType) {
            $groupKey = NativeFields::defaultGroupKey($entityType);

            $exists = AttributeGroup::query()
                ->where('entity_type', $entityType)
                ->where('key', $groupKey)
                ->exists();

            if ($exists) {
                continue;
            }

            $group = AttributeGroup::query()->create([
                'entity_type' => $entityType,
                'key' => $groupKey,
                'label' => NativeFields::defaultGroupLabel($entityType),
                'display_order' => 0,
                'is_system' => true,
            ]);

            foreach (NativeFields::defaultLayoutKeys($entityType) as $index => $nativeKey) {
                NativeFields::assertExists($entityType, $nativeKey);

                LayoutField::query()->create([
                    'group_id' => $group->id,
                    'entity_type' => $entityType,
                    'display_order' => $index,
                    'field_type' => LayoutFieldType::Native,
                    'native_field_key' => $nativeKey,
                    'attribute_definition_id' => null,
                ]);
            }
        }
    }
}
