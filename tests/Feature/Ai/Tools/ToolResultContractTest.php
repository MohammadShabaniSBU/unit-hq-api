<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Tools\EntityArgumentExemptions;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\RefsRenderer;
use App\Support\Ai\Tools\RuntimeOnlyTools;
use App\Support\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\Ai\RegistryFixtures;
use Tests\TestCase;

class ToolResultContractTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    /** @var array<string, EntityType> */
    private const ID_KEYS = [
        'site_id' => EntityType::Site,
        'unit_class_id' => EntityType::UnitClass,
        'contact_id' => EntityType::Contact,
        'deal_id' => EntityType::Deal,
        'offer_id' => EntityType::Offer,
        'reservation_id' => EntityType::Reservation,
        'contract_id' => EntityType::Contract,
        'discount_id' => EntityType::Discount,
        'size_guide_id' => EntityType::SizeGuide,
        'task_id' => EntityType::Task,
        'note_id' => EntityType::Note,
        'invoice_id' => EntityType::Invoice,
        'unit_id' => EntityType::Unit,
    ];

    /** @var array<string, EntityType> */
    private const COLLECTION_ID = [
        'discounts' => EntityType::Discount,
        'bands' => EntityType::SizeGuide,
        'invoices' => EntityType::Invoice,
    ];

    /** Catalogue / option ids that are not EntityRef types. */
    private const SKIP_ID_KEYS = EntityArgumentExemptions::KEYS;

    #[Test]
    public function every_registered_tool_has_a_fixture_whose_entities_cover_payload_ids(): void
    {
        $fixtures = new RegistryFixtures;
        $fixtures->seed();

        $tools = app(ToolRegistry::class)->all();
        $this->assertNotEmpty($tools);

        foreach ($tools as $key => $tool) {
            if (RuntimeOnlyTools::contains($key)) {
                continue;
            }
            $fixture = $fixtures->for($key);
            $result = $this->dispatchTool(
                $fixture['agent'],
                $key,
                $fixture['principal'],
                $fixture['arguments'],
                $fixture['ctx'],
            );

            $this->assertArrayNotHasKey(
                'entities',
                $result->data,
                "Tool [{$key}] data must not declare reserved key entities.",
            );
            $this->assertArrayNotHasKey(
                'error',
                $result->data,
                "Tool [{$key}] data must not declare reserved key error.",
            );

            $needed = $this->collectEntityIds($result->data);
            $got = $this->indexEntities($result->entities);
            $refsLine = RefsRenderer::render($result->entities);

            foreach ($needed as [$type, $id]) {
                $this->assertTrue(
                    isset($got[$type->value.':'.$id]),
                    "Tool [{$key}] payload names {$type->value}#{$id} without a matching EntityRef.",
                );
                $this->assertStringContainsString(
                    "{$type->value} {$id} = ",
                    $refsLine,
                    "Tool [{$key}] payload names {$type->value}#{$id} without it reaching the Refs: line.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{0: EntityType, 1: int}>
     */
    private function collectEntityIds(array $data, ?EntityType $relatedType = null): array
    {
        $found = [];

        if (isset($data['related_to_type'], $data['related_to_id']) && is_string($data['related_to_type'])) {
            $relatedType = EntityType::tryFrom($data['related_to_type']);
        }

        foreach ($data as $key => $value) {
            if ($key === 'entities' || $key === 'error' || in_array($key, self::SKIP_ID_KEYS, true)) {
                continue;
            }

            if (is_string($key) && isset(self::ID_KEYS[$key]) && $this->positiveInt($value) !== null) {
                $found[] = [self::ID_KEYS[$key], $this->positiveInt($value)];
            }

            if ($key === 'related_to_id' && $relatedType !== null && $this->positiveInt($value) !== null) {
                $found[] = [$relatedType, $this->positiveInt($value)];
            }

            if (is_array($value)) {
                if (is_string($key) && isset(self::COLLECTION_ID[$key]) && array_is_list($value)) {
                    foreach ($value as $row) {
                        if (is_array($row) && $this->positiveInt($row['id'] ?? null) !== null) {
                            $found[] = [self::COLLECTION_ID[$key], $this->positiveInt($row['id'])];
                        }
                        if (is_array($row)) {
                            $found = array_merge($found, $this->collectEntityIds($row, $relatedType));
                        }
                    }

                    continue;
                }

                $found = array_merge($found, $this->collectEntityIds($value, $relatedType));
            }
        }

        return $found;
    }

    /**
     * @param  list<EntityRef>  $entities
     * @return array<string, true>
     */
    private function indexEntities(array $entities): array
    {
        $got = [];
        foreach ($entities as $entity) {
            $got[$entity->type->value.':'.$entity->id] = true;
        }

        return $got;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
