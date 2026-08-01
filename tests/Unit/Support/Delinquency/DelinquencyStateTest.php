<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Delinquency;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\Allocation;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Delinquency\DelinquencyState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DelinquencyStateTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Site $site;

    private Contract $contract;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        // Site is Europe/Madrid; freeze UTC so site-today crosses midnight differently.
        Carbon::setTestNow(Carbon::parse('2026-08-15 22:30:00', 'UTC'));

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = $price->id;
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $this->contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);

        ContractItem::query()->create([
            'contract_id' => $this->contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_bases_and_exclusion_sets(): void
    {
        // Trigger set includes late_fee; fee base does not.
        $this->assertNotEquals(
            array_map(fn (ChargeType $t) => $t->value, DelinquencyState::TRIGGER_TYPES),
            array_map(fn (ChargeType $t) => $t->value, DelinquencyState::FEE_BASE_TYPES),
        );
        $this->assertContains(ChargeType::LateFee, DelinquencyState::TRIGGER_TYPES);
        $this->assertNotContains(ChargeType::LateFee, DelinquencyState::FEE_BASE_TYPES);
        $this->assertNotContains(ChargeType::Deposit, DelinquencyState::TRIGGER_TYPES);

        // Site-today in Madrid on 2026-08-16 00:30 — UTC freeze is 22:30 on the 15th.
        $this->assertSame('2026-08-16', DelinquencyState::siteToday($this->contract)->toDateString());

        $rent = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);
        $insurance = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Insurance,
            'amount' => '20.00',
            'net_amount' => '20.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-05',
        ]);
        $lateFee = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::LateFee,
            'amount' => '15.00',
            'net_amount' => '15.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-10',
        ]);
        // Deposit overdue — must not trigger delinquency.
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Deposit,
            'amount' => '200.00',
            'net_amount' => '200.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
        ]);
        // Not yet due (due = site-today).
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-16',
        ]);

        // Partial allocation on rent: 40 of 100 open → 60.
        $payment = Payment::factory()->create([
            'contract_id' => $this->contract->id,
            'amount' => '40.00',
            'currency' => 'EUR',
        ]);
        Allocation::factory()->create([
            'payment_id' => $payment->id,
            'charge_id' => $rent->id,
            'amount' => '40.00',
        ]);

        $this->assertTrue(DelinquencyState::isDelinquent($this->contract));

        $overdue = DelinquencyState::overdueCharges($this->contract);
        $types = $overdue->pluck('charge_type')->map(fn ($t) => $t instanceof ChargeType ? $t->value : $t)->all();
        $this->assertSame(
            [ChargeType::Rent->value, ChargeType::Insurance->value, ChargeType::LateFee->value],
            $types,
        );
        $this->assertSame('60.00', $overdue->firstWhere('id', $rent->id)?->openAmount());

        // Fee base ignores late_fee and deposit; uses open portions of rent+insurance.
        $this->assertSame('80.00', DelinquencyState::overdueBase($this->contract));

        // daysOverdue from oldest unpaid (rent due 2026-08-01) to site-today 2026-08-16.
        $this->assertSame(15, DelinquencyState::daysOverdue($this->contract));

        // Paying everything except deposit cures trigger set.
        foreach ([$rent, $insurance, $lateFee] as $charge) {
            $open = $charge->fresh()->openAmount();
            if (bccomp($open, '0.00', 2) <= 0) {
                continue;
            }
            $p = Payment::factory()->create([
                'contract_id' => $this->contract->id,
                'amount' => $open,
                'currency' => 'EUR',
            ]);
            Allocation::factory()->create([
                'payment_id' => $p->id,
                'charge_id' => $charge->id,
                'amount' => $open,
            ]);
        }

        $this->assertFalse(DelinquencyState::isDelinquent($this->contract->fresh()));
        $this->assertNull(DelinquencyState::daysOverdue($this->contract->fresh()));
        $this->assertSame('0.00', DelinquencyState::overdueBase($this->contract->fresh()));
    }
}
