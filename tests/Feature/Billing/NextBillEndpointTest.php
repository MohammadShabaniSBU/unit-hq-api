<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\BillingRunItemOutcome;
use App\Enums\BillingRunTrigger;
use App\Enums\ContractStatus;
use App\Models\BillingRunItem;
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

class NextBillEndpointTest extends TestCase
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
                'amount' => '100.00',
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

        Setting::setBilling(Setting::billing()->with(defaultDepositAmount: '0.00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_matches_job_output(): void
    {
        // One period due within horizon (site-today = 2026-08-15) so the run
        // bills exactly the next-bill estimate — not a multi-period catch-up.
        $contract = $this->makeBillableContract(billedThrough: '2026-08-15');

        $estimate = $this->getJson("/api/contracts/{$contract->id}/next-bill");
        $estimate->assertOk();
        $estimate->assertJsonPath('data.window.start', '2026-08-15');
        $estimate->assertJsonPath('data.window.end', '2026-09-15');
        $estimate->assertJsonPath('data.amount', '100.00');
        $estimate->assertJsonPath('data.currency', 'EUR');

        $run = (new BillingRunEngine)->run(
            BillingRunTrigger::Manual,
            contractId: $contract->id,
        );

        $this->assertSame(1, $run->contracts_billed);

        /** @var BillingRunItem $item */
        $item = BillingRunItem::query()
            ->where('billing_run_id', $run->id)
            ->where('contract_id', $contract->id)
            ->firstOrFail();

        $this->assertSame(BillingRunItemOutcome::Billed, $item->outcome);
        $this->assertSame(1, $item->periods_billed);
        $this->assertSame(
            $estimate->json('data.amount'),
            (string) $item->amount_total,
        );
        $this->assertSame(
            $estimate->json('data.currency'),
            $item->currency,
        );

        // After the run advances the cursor, next-bill describes the following period.
        $after = $this->getJson("/api/contracts/{$contract->id}/next-bill");
        $after->assertOk();
        $after->assertJsonPath('data.window.start', '2026-09-15');
        $after->assertJsonPath('data.amount', '100.00');
    }

    public function test_null_when_ended_or_nothing_due(): void
    {
        $ended = $this->makeBillableContract(billedThrough: '2026-07-15');
        $ended->forceFill(['status' => ContractStatus::Ended])->save();

        $this->getJson("/api/contracts/{$ended->id}/next-bill")
            ->assertOk()
            ->assertJsonPath('data', null);

        // Horizon-gated: cursor already past site-today with horizon 0 → still returns
        // the *next* period estimate (display), even if the run would skip as not_due.
        // Ended/cancelled are the hard nulls; nothing-due for active is still an estimate.
        $active = $this->makeBillableContract(billedThrough: '2026-08-15');
        $activeEstimate = $this->getJson("/api/contracts/{$active->id}/next-bill");
        $activeEstimate->assertOk();
        $this->assertNotNull($activeEstimate->json('data'));
        $activeEstimate->assertJsonPath('data.window.start', '2026-08-15');
    }

    private function makeBillableContract(string $billedThrough): Contract
    {
        $contact = Contact::factory()->create();

        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'billing_interval' => BillingInterval::Month,
            'billing_interval_count' => 1,
            'billing_anchor_model' => BillingAnchorModel::Anniversary,
            'billing_anchor_date' => '2026-01-15',
            'move_in_date' => '2026-01-15',
            'billed_through' => $billedThrough,
            'start_date' => '2026-01-15',
        ]);

        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
        ]);

        return $contract;
    }
}
