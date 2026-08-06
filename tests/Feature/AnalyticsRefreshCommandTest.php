<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class AnalyticsRefreshCommandTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_completes_within_budget(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertSame(0, Artisan::call('analytics:refresh'));

            return;
        }

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'UTC',
            'currency' => 'EUR',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2023-01-01'],
        );

        for ($i = 0; $i < 20; $i++) {
            $unit = Unit::factory()->create([
                'site_id' => $site->id,
                'unit_class_id' => $unitClass->id,
                'unit_number' => 'U-'.$i,
            ]);
            $contact = Contact::factory()->create();
            $contract = Contract::factory()->create([
                'contact_id' => $contact->id,
                'currency' => 'EUR',
                'status' => ContractStatus::Active,
            ]);
            ContractItem::query()->create([
                'contract_id' => $contract->id,
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'price_id' => $price->id,
                'effective_from' => '2023-01-01',
                'effective_to' => null,
            ]);
            UnitOccupancy::query()->create([
                'unit_id' => $unit->id,
                'contract_id' => $contract->id,
                'started_on' => '2023-01-01',
                'ended_on' => null,
            ]);
        }

        $started = microtime(true);
        $exit = Artisan::call('analytics:refresh');
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $exit);
        $this->assertLessThan(30.0, $elapsed, 'analytics:refresh exceeded 30s budget');

        $this->assertTrue(
            SystemEvent::query()->where('event', 'analytics'.'.refresh.started')->exists(),
        );
        $this->assertTrue(
            SystemEvent::query()->where('event', 'analytics'.'.refresh.committed')->exists(),
        );
    }
}
