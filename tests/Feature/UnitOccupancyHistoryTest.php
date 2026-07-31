<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitOccupancyHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        $unitClass = UnitClass::factory()->create();
        $this->unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    public function test_lists_occupancies_newest_first_with_tenant(): void
    {
        $olderContact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        $newerContact = Contact::factory()->create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
        ]);

        $olderContract = Contract::factory()->create([
            'contact_id' => $olderContact->id,
            'currency' => 'EUR',
        ]);
        $newerContract = Contract::factory()->create([
            'contact_id' => $newerContact->id,
            'currency' => 'EUR',
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $olderContract->id,
            'started_on' => '2025-01-01',
            'ended_on' => '2025-06-01',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $newerContract->id,
            'started_on' => '2025-06-01',
            'ended_on' => null,
        ]);

        $response = $this->getJson("/api/units/{$this->unit->id}/occupancies");
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame($newerContract->id, $data[0]['contract_id']);
        $this->assertSame('Grace Hopper', $data[0]['tenant_name']);
        $this->assertNull($data[0]['ended_on']);
        $this->assertSame($olderContract->id, $data[1]['contract_id']);
        $this->assertSame('Ada Lovelace', $data[1]['tenant_name']);
        $this->assertSame('2025-06-01', $data[1]['ended_on']);
    }

    public function test_empty_history_returns_empty_array(): void
    {
        $response = $this->getJson("/api/units/{$this->unit->id}/occupancies");
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }
}
