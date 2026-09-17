<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use Database\Seeders\DefaultDealAttributeDefinitionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DefaultDealAttributeDefinitionsSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeds_deal_unit_requirements_idempotently(): void
    {
        $this->seed(DefaultDealAttributeDefinitionsSeeder::class);
        $this->seed(DefaultDealAttributeDefinitionsSeeder::class);

        $definitions = AttributeDefinition::query()
            ->with('options')
            ->where('entity_type', AttributeEntityType::Deal)
            ->orderBy('display_order')
            ->get();

        $this->assertCount(3, $definitions);
        $this->assertSame(3, AttributeDefinition::query()->count());
        $this->assertSame(
            ['climate_control_required', 'vehicle_storage_needed', 'access_frequency'],
            $definitions->pluck('key')->all()
        );

        $accessFrequency = $definitions->firstWhere('key', 'access_frequency');
        $this->assertSame(AttributeType::Select, $accessFrequency?->type);
        $this->assertGreaterThan(0, $accessFrequency?->options->count());
    }
}
