<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Occupancy;

use App\Enums\HoldType;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Support\Occupancy\HoldGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HoldGuardTest extends TestCase
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

    public function test_unheld_unit_passes(): void
    {
        HoldGuard::assertUnheld(
            $this->unit->id,
            CarbonImmutable::parse('2026-03-01'),
            null,
        );

        $this->addToAssertionCount(1);
    }

    public function test_overlapping_open_hold_fails(): void
    {
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-03-01',
            'ends_on' => null,
            'reason' => 'Flood',
        ]);

        $this->expectException(ValidationException::class);

        HoldGuard::assertUnheld(
            $this->unit->id,
            CarbonImmutable::parse('2026-03-15'),
            null,
        );
    }

    public function test_released_hold_does_not_block(): void
    {
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-03-01',
            'ends_on' => null,
            'released_at' => now(),
            'reason' => 'Flood',
        ]);

        HoldGuard::assertUnheld(
            $this->unit->id,
            CarbonImmutable::parse('2026-03-15'),
            null,
        );

        $this->addToAssertionCount(1);
    }

    public function test_overlock_does_not_block_assert_unheld(): void
    {
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Overlock,
            'starts_on' => '2026-03-01',
            'ends_on' => null,
        ]);

        HoldGuard::assertUnheld(
            $this->unit->id,
            CarbonImmutable::parse('2026-03-15'),
            null,
        );

        $this->addToAssertionCount(1);
    }

    public function test_adjacent_half_open_ranges_pass(): void
    {
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-02-01',
            'ends_on' => '2026-03-01',
            'reason' => 'Paint',
        ]);

        HoldGuard::assertUnheld(
            $this->unit->id,
            CarbonImmutable::parse('2026-03-01'),
            null,
        );

        $this->addToAssertionCount(1);
    }
}
