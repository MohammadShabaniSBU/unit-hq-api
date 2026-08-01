<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\BillingRunTrigger;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\BillingRunItem;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Billing\BillingRunEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class HorizonTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => $entity->id,
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '25.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $this->priceId = $price->id;
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_gates_when_not_what(): void
    {
        // Next period starts 2026-08-17 (2 days ahead of site-today 2026-08-15).
        $contract = $this->makeDailyContract(billedThrough: '2026-08-17');

        Setting::setBilling(Setting::billing()->with(billingHorizonDays: 0));
        $zeroRun = (new BillingRunEngine)->run(BillingRunTrigger::Manual);

        $this->assertSame(0, $zeroRun->contracts_billed);
        $this->assertSame(0, $zeroRun->contracts_considered);
        $this->assertSame(0, Charge::query()->where('contract_id', $contract->id)->count());

        $contract->refresh();
        $this->assertSame('2026-08-17', $contract->billedThrough());

        Setting::setBilling(Setting::billing()->with(billingHorizonDays: 3));
        $horizonRun = (new BillingRunEngine)->run(BillingRunTrigger::Manual);

        $this->assertSame(1, $horizonRun->contracts_billed);

        $item = BillingRunItem::query()
            ->where('billing_run_id', $horizonRun->id)
            ->where('contract_id', $contract->id)
            ->first();
        $this->assertNotNull($item);
        // Horizon 3 from 2026-08-15 includes starts on 08-17 and 08-18.
        $this->assertSame(2, $item->periods_billed);

        $rent = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Rent)
            ->whereDate('period_start', '2026-08-17')
            ->first();
        $this->assertNotNull($rent);
        $this->assertSame('25.00', $rent->amount);
        $this->assertSame('2026-08-17', $rent->period_start->toDateString());
        $this->assertSame('2026-08-18', $rent->period_end->toDateString());

        // Horizon only gates *when*; window + amount are cadence/price facts.
        Setting::setBilling(Setting::billing()->with(billingHorizonDays: 0));
        $this->assertSame(0, Setting::billing()->billingHorizonDays);
        $this->assertSame('25.00', $rent->fresh()->amount);
        $this->assertSame('2026-08-17', $rent->fresh()->period_start->toDateString());
    }

    private function makeDailyContract(string $billedThrough): Contract
    {
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'billing_interval' => BillingInterval::Day,
            'billing_interval_count' => 1,
            'billing_anchor_model' => BillingAnchorModel::Anniversary,
            'billing_anchor_date' => '2026-08-01',
            'move_in_date' => '2026-08-01',
            'billed_through' => $billedThrough,
            'start_date' => '2026-08-01',
        ]);

        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-08-01',
            'effective_to' => null,
        ]);

        return $contract;
    }
}
