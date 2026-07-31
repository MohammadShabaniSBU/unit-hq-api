<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Occupancy;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Occupancy\OccupancyGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OccupancyGuardTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id]);
        $unitClass = UnitClass::factory()->create();
        $this->unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    public function test_vacant_unit_passes(): void
    {
        OccupancyGuard::assertVacant(
            $this->unit->id,
            CarbonImmutable::parse('2026-03-01'),
            null,
        );

        $this->addToAssertionCount(1);
    }

    public function test_overlapping_open_occupancy_fails(): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-03-01',
            'ended_on' => null,
        ]);

        $this->expectException(ValidationException::class);

        OccupancyGuard::assertVacant(
            $this->unit->id,
            CarbonImmutable::parse('2026-03-15'),
            null,
        );
    }

    public function test_adjacent_half_open_ranges_pass(): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-02-01',
            'ended_on' => '2026-03-01',
        ]);

        OccupancyGuard::assertVacant(
            $this->unit->id,
            CarbonImmutable::parse('2026-03-01'),
            null,
        );

        $this->addToAssertionCount(1);
    }
}
