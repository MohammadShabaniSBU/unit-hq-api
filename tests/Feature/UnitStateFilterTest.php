<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitStateFilterTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private UnitClass $unitClass;

    protected function setUp(): void
    {
        parent::setUp();

        Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        $this->unitClass = UnitClass::factory()->create();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00', 'Europe/Madrid'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_index_filters_by_state_and_state_group(): void
    {
        $available = $this->unit('A-1');
        $occupied = $this->unit('A-2');
        $reserved = $this->unit('A-3');
        $maintenance = $this->unit('A-4');

        UnitOccupancy::query()->create([
            'unit_id' => $occupied->id,
            'contract_id' => $this->contract()->id,
            'started_on' => '2026-07-01',
            'ended_on' => null,
        ]);
        UnitHold::query()->create([
            'unit_id' => $reserved->id,
            'hold_type' => HoldType::Reservation,
            'starts_on' => '2026-07-01',
            'ends_on' => null,
        ]);
        UnitHold::query()->create([
            'unit_id' => $maintenance->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-07-01',
            'ends_on' => null,
            'reason' => 'Paint',
        ]);

        $occupiedResponse = $this->getJson('/api/units?state=occupied&per_page=100');
        $occupiedResponse->assertOk();
        $occupiedNumbers = collect($occupiedResponse->json('data'))->pluck('unit_number');
        $this->assertTrue($occupiedNumbers->contains('A-2'));
        $this->assertFalse($occupiedNumbers->contains('A-1'));

        $oosResponse = $this->getJson('/api/units?state_group=out_of_service&per_page=100');
        $oosResponse->assertOk();
        $oosNumbers = collect($oosResponse->json('data'))->pluck('unit_number');
        $this->assertTrue($oosNumbers->contains('A-4'));
        $this->assertFalse($oosNumbers->contains('A-3'));
        $this->assertFalse($oosNumbers->contains((string) $available->unit_number));

        $availableResponse = $this->getJson('/api/units?state=available&per_page=100');
        $availableResponse->assertOk();
        $availableNumbers = collect($availableResponse->json('data'))->pluck('unit_number');
        $this->assertTrue($availableNumbers->contains('A-1'));
        $this->assertFalse($availableNumbers->contains('A-2'));
        $this->assertFalse($availableNumbers->contains('A-4'));
    }

    public function test_for_map_includes_state_and_current_hold(): void
    {
        $unit = $this->unit('M-1');
        UnitHold::query()->create([
            'unit_id' => $unit->id,
            'hold_type' => HoldType::Damaged,
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-08-01',
            'reason' => 'Door',
        ]);

        $response = $this->getJson("/api/units?site_id={$this->site->id}&for_map=1");
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('unit_number', 'M-1');
        $this->assertNotNull($row);
        $this->assertSame('damaged', $row['state']);
        $this->assertSame('damaged', $row['current_hold']['hold_type']);
        $this->assertSame('2026-08-01', $row['current_hold']['ends_on']);
    }

    private function unit(string $number): Unit
    {
        return Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $number,
        ]);
    }

    private function contract(): Contract
    {
        return Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
        ]);
    }
}
