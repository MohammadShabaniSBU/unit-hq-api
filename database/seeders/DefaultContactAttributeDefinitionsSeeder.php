<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use App\Models\LayoutField;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Inserts the default contact custom-attribute catalogue.
 *
 * Idempotent: skips a definition when the entity_type + key already exists.
 *
 *   php artisan db:seed --class=DefaultContactAttributeDefinitionsSeeder
 */
class DefaultContactAttributeDefinitionsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Unit-requirement keys that were briefly seeded on Contact.
     * A contact can pursue two units with different needs; those live on Deal.
     *
     * @var list<string>
     */
    public const RETIRED_UNIT_REQUIREMENT_KEYS = [
        'climate_control_required',
        'vehicle_storage_needed',
        'access_frequency',
    ];

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
                'key' => 'preferred_contact_method',
                'label' => 'Preferred contact method',
                'type' => AttributeType::Select,
                'group_name' => 'Contact preferences',
                'display_order' => 0,
                'options' => ['phone', 'email', 'sms'],
            ],
            [
                'key' => 'preferred_contact_window',
                'label' => 'Preferred contact window',
                'type' => AttributeType::Select,
                'group_name' => 'Contact preferences',
                'display_order' => 1,
                'options' => ['morning', 'afternoon', 'evening', 'anytime'],
            ],
            [
                'key' => 'do_not_contact',
                'label' => 'Do not contact',
                'type' => AttributeType::Boolean,
                'group_name' => 'Contact preferences',
                'display_order' => 2,
            ],
            [
                'key' => 'payment_arrangement_in_place',
                'label' => 'Payment arrangement in place',
                'type' => AttributeType::Boolean,
                'group_name' => 'Collections',
                'display_order' => 3,
            ],
            [
                'key' => 'gate_access_suspended',
                'label' => 'Gate access suspended',
                'type' => AttributeType::Boolean,
                'group_name' => 'Collections',
                'display_order' => 4,
            ],
            [
                'key' => 'loyalty_tier',
                'label' => 'Loyalty tier',
                'type' => AttributeType::Select,
                'group_name' => 'Retention',
                'display_order' => 5,
                'options' => ['none', 'bronze', 'silver', 'gold'],
            ],
            [
                'key' => 'churn_risk_score',
                'label' => 'Churn risk score',
                'type' => AttributeType::Number,
                'group_name' => 'Retention',
                'display_order' => 6,
            ],
            [
                'key' => 'nps_score',
                'label' => 'NPS score',
                'type' => AttributeType::Number,
                'group_name' => 'Retention',
                'display_order' => 7,
            ],
        ];
    }

    public function run(): void
    {
        $this->retireMisplacedUnitRequirements();

        foreach ($this->definitions() as $def) {
            $exists = AttributeDefinition::query()
                ->where('entity_type', AttributeEntityType::Contact)
                ->where('key', $def['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            $definition = AttributeDefinition::query()->create([
                'entity_type' => AttributeEntityType::Contact,
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

    /**
     * Archive-only (invariant 21). Also drop contact-card placements so a
     * previously added climate-control field does not keep showing on Contact.
     */
    private function retireMisplacedUnitRequirements(): void
    {
        $definitions = AttributeDefinition::query()
            ->where('entity_type', AttributeEntityType::Contact)
            ->whereIn('key', self::RETIRED_UNIT_REQUIREMENT_KEYS)
            ->get();

        if ($definitions->isEmpty()) {
            return;
        }

        $ids = $definitions->pluck('id');

        LayoutField::query()
            ->whereIn('attribute_definition_id', $ids)
            ->delete();

        AttributeDefinition::query()
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }
}
