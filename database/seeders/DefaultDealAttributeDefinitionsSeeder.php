<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Inserts the default deal custom-attribute catalogue.
 *
 * Unit requirements live here — not on Contact — so one person can pursue
 * two units with different needs (climate, vehicle storage, access).
 *
 * Idempotent: skips a definition when the entity_type + key already exists.
 *
 *   php artisan db:seed --class=DefaultDealAttributeDefinitionsSeeder
 */
class DefaultDealAttributeDefinitionsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     type: AttributeType,
     *     group_name: string,
     *     display_order: int,
     *     options?: list<string>
     * }>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => 'climate_control_required',
                'label' => 'Climate control required',
                'type' => AttributeType::Boolean,
                'group_name' => 'Unit requirements',
                'display_order' => 0,
            ],
            [
                'key' => 'vehicle_storage_needed',
                'label' => 'Vehicle storage needed',
                'type' => AttributeType::Boolean,
                'group_name' => 'Unit requirements',
                'display_order' => 1,
            ],
            [
                'key' => 'access_frequency',
                'label' => 'Access frequency',
                'type' => AttributeType::Select,
                'group_name' => 'Unit requirements',
                'display_order' => 2,
                'options' => ['occasional', 'frequent', 'business_inventory'],
            ],
        ];
    }

    public function run(): void
    {
        foreach ($this->definitions() as $def) {
            $exists = AttributeDefinition::query()
                ->where('entity_type', AttributeEntityType::Deal)
                ->where('key', $def['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            $definition = AttributeDefinition::query()->create([
                'entity_type' => AttributeEntityType::Deal,
                'key' => $def['key'],
                'label' => $def['label'],
                'type' => $def['type'],
                'group_name' => $def['group_name'],
                'display_order' => $def['display_order'],
            ]);

            foreach ($def['options'] ?? [] as $index => $label) {
                $definition->options()->create([
                    'label' => $label,
                    'display_order' => $index,
                ]);
            }
        }
    }
}
