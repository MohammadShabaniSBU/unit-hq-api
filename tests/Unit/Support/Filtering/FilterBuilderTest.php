<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Filtering;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Enums\ContactLifecycleStatus;
use App\Models\AttributeDefinition;
use App\Models\AttributeOption;
use App\Models\AttributeValue;
use App\Models\Contact;
use App\Support\Filtering\FilterBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FilterBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_nested_or_inside_and_returns_correct_rows(): void
    {
        $lifetime = $this->makeNumberDefinition('lifetime_value', 'Lifetime value');
        $tier = $this->makeSelectDefinition('tier', 'Tier', ['Gold', 'Silver']);
        $goldId = $tier->options->firstWhere('label', 'Gold')->id;

        $matchByNumber = Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        $this->setNumberValue($lifetime, $matchByNumber->id, 2000);

        $matchByTier = Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        $this->setSelectValue($tier, $matchByTier->id, $goldId);

        $prospectNeither = Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        $this->setNumberValue($lifetime, $prospectNeither->id, 500);

        $leadHighValue = Contact::factory()->create(['status' => ContactLifecycleStatus::Lead]);
        $this->setNumberValue($lifetime, $leadHighValue->id, 2000);

        $ids = $this->filterIds([
            'op' => 'and',
            'conditions' => [
                ['field' => 'status', 'op' => 'eq', 'value' => 'prospect'],
                [
                    'op' => 'or',
                    'conditions' => [
                        ['field' => "attr:{$lifetime->id}", 'op' => 'gt', 'value' => 1000],
                        ['field' => "attr:{$tier->id}", 'op' => 'eq', 'value' => $goldId],
                    ],
                ],
            ],
        ]);

        $this->assertEqualsCanonicalizing(
            [$matchByNumber->id, $matchByTier->id],
            $ids,
        );
    }

    public function test_is_empty_and_neq_treat_missing_attribute_rows_correctly(): void
    {
        $lifetime = $this->makeNumberDefinition('lifetime_value', 'Lifetime value');

        $missing = Contact::factory()->create();
        $equal = Contact::factory()->create();
        $this->setNumberValue($lifetime, $equal->id, 1000);
        $other = Contact::factory()->create();
        $this->setNumberValue($lifetime, $other->id, 50);

        $emptyIds = $this->filterIds([
            'op' => 'and',
            'conditions' => [
                ['field' => "attr:{$lifetime->id}", 'op' => 'is_empty', 'value' => null],
            ],
        ]);

        $this->assertEqualsCanonicalizing([$missing->id], $emptyIds);

        $neqIds = $this->filterIds([
            'op' => 'and',
            'conditions' => [
                ['field' => "attr:{$lifetime->id}", 'op' => 'neq', 'value' => 1000],
            ],
        ]);

        $this->assertEqualsCanonicalizing([$missing->id, $other->id], $neqIds);
        $this->assertNotContains($equal->id, $neqIds);
    }

    public function test_multiselect_all_of_requires_every_option(): void
    {
        $tags = $this->makeMultiselectDefinition('tags', 'Tags', ['Gold', 'Platinum', 'Silver']);
        $goldId = $tags->options->firstWhere('label', 'Gold')->id;
        $platinumId = $tags->options->firstWhere('label', 'Platinum')->id;

        $onlyGold = Contact::factory()->create();
        $this->setMultiselectValue($tags, $onlyGold->id, [$goldId]);

        $both = Contact::factory()->create();
        $this->setMultiselectValue($tags, $both->id, [$goldId, $platinumId]);

        $neither = Contact::factory()->create();

        $ids = $this->filterIds([
            'op' => 'and',
            'conditions' => [
                [
                    'field' => "attr:{$tags->id}",
                    'op' => 'all_of',
                    'value' => [$goldId, $platinumId],
                ],
            ],
        ]);

        $this->assertEqualsCanonicalizing([$both->id], $ids);
        $this->assertNotContains($onlyGold->id, $ids);
        $this->assertNotContains($neither->id, $ids);
    }

    /**
     * @param  array{op: string, conditions: list<array<string, mixed>>}  $filter
     * @return list<int>
     */
    private function filterIds(array $filter): array
    {
        Cache::flush();

        $query = Contact::query();
        FilterBuilder::for(AttributeEntityType::Contact)->apply($query, $filter);

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function makeNumberDefinition(string $key, string $label): AttributeDefinition
    {
        return AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => $key,
            'label' => $label,
            'type' => AttributeType::Number,
            'display_order' => 0,
        ]);
    }

    /**
     * @param  list<string>  $labels
     */
    private function makeSelectDefinition(string $key, string $label, array $labels): AttributeDefinition
    {
        $definition = AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => $key,
            'label' => $label,
            'type' => AttributeType::Select,
            'display_order' => 0,
        ]);

        foreach ($labels as $index => $optionLabel) {
            AttributeOption::query()->create([
                'definition_id' => $definition->id,
                'label' => $optionLabel,
                'display_order' => $index,
            ]);
        }

        return $definition->load('options');
    }

    /**
     * @param  list<string>  $labels
     */
    private function makeMultiselectDefinition(string $key, string $label, array $labels): AttributeDefinition
    {
        $definition = AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => $key,
            'label' => $label,
            'type' => AttributeType::Multiselect,
            'display_order' => 0,
        ]);

        foreach ($labels as $index => $optionLabel) {
            AttributeOption::query()->create([
                'definition_id' => $definition->id,
                'label' => $optionLabel,
                'display_order' => $index,
            ]);
        }

        return $definition->load('options');
    }

    private function setNumberValue(AttributeDefinition $definition, int $entityId, float|int $value): void
    {
        AttributeValue::query()->create([
            'definition_id' => $definition->id,
            'entity_id' => $entityId,
            'value_number' => $value,
        ]);
    }

    private function setSelectValue(AttributeDefinition $definition, int $entityId, int $optionId): void
    {
        AttributeValue::query()->create([
            'definition_id' => $definition->id,
            'entity_id' => $entityId,
            'value_option_id' => $optionId,
        ]);
    }

    /**
     * @param  list<int>  $optionIds
     */
    private function setMultiselectValue(AttributeDefinition $definition, int $entityId, array $optionIds): void
    {
        $value = AttributeValue::query()->create([
            'definition_id' => $definition->id,
            'entity_id' => $entityId,
        ]);

        $value->options()->sync($optionIds);
    }
}
