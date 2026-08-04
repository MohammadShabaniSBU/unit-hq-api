<?php

declare(strict_types=1);

namespace Tests\Feature\Discounts;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Discounts\AppliesDiscountPlan;
use App\Support\Discounts\CompileContext;
use App\Support\Discounts\DiscountCompiler;
use App\Support\Reports\OccupancyMetrics;
use App\Support\Reports\OccupancyReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ReportsUnchangedTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_economic_drag_visible(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'name' => 'Madrid Hub',
        ]);
        $unitClass = UnitClass::factory()->create([
            'code' => 'S10',
            'label' => 'Small 10',
            'size' => '10.00',
        ]);
        [, $cataloguePrice] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $cataloguePrice->id]);

        // 3 rentable units (one under maintenance).
        $u1 = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => 'A-1',
            'enabled' => true,
        ]);
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => 'A-2',
            'enabled' => true,
        ]);
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => 'A-3',
            'enabled' => true,
        ]);
        $maint = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => 'M-1',
            'enabled' => true,
        ]);
        UnitHold::query()->create([
            'unit_id' => $maint->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'reason' => 'Roof',
        ]);

        $discount = Discount::factory()->freeTime()->create();
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'billing_interval' => 'week',
            'billing_interval_count' => 4,
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
        ]);

        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $u1->id,
            'price_id' => $cataloguePrice->id,
            'effective_from' => '2026-08-03',
            'effective_to' => null,
        ]);

        $plan = DiscountCompiler::compile($discount, new CompileContext(
            listAmount: '100.00',
            currency: 'EUR',
            interval: 'week',
            intervalCount: 4,
            anchorDate: '2026-08-03',
            commitmentWeeks: 8,
        ));
        AppliesDiscountPlan::apply($item, $discount, $plan, '100.00', $employee->id);

        UnitOccupancy::query()->create([
            'unit_id' => $u1->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-08-03',
            'ended_on' => null,
        ]);

        // As-of inside the free period: economic numerator is €0 vs catalogue €100.
        $snap = OccupancyMetrics::snapshot('2026-08-15', [$site->id]);
        $this->assertSame(1, $snap['occupied_units']);
        $this->assertSame(3, $snap['rentable_units']);
        $this->assertSame(33.3, $snap['unit_rate']);
        $this->assertSame('0.00', $snap['economic_numerator']);
        $this->assertSame('300.00', $snap['economic_denominator']);
        $this->assertSame(0.0, $snap['economic_rate']);
        $this->assertLessThan($snap['unit_rate'], $snap['economic_rate']);

        $report = (new OccupancyReport)->run(new ReportFilters(
            siteIds: [$site->id],
            asOf: '2026-08-15',
        ));
        $this->assertSame(33.3, $report->meta['headlines']['unit']['rate']);
        $this->assertSame(0.0, $report->meta['headlines']['economic']['rate']);

        // Reports / metrics sources must not mention discounts.
        $metrics = file_get_contents(app_path('Support/Reports/OccupancyMetrics.php'));
        $this->assertIsString($metrics);
        $this->assertStringNotContainsString('discount', strtolower($metrics));

        CarbonImmutable::setTestNow();
    }
}
