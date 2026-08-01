<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\BillingRunItemOutcome;
use App\Enums\BillingRunTrigger;
use App\Enums\ContractStatus;
use App\Models\BillingRun;
use App\Models\BillingRunItem;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class BillingRunApiTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => $entity->id,
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $this->employee->id,
            [
                'amount' => '100.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        $this->contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'billed_through' => '2026-08-15',
            'billing_anchor_date' => '2026-01-15',
            'start_date' => '2026-01-15',
            'move_in_date' => '2026-01-15',
        ]);
        $this->contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_list_detail_filters(): void
    {
        $this->getJson('/api/billing-runs')->assertUnauthorized();

        $run = $this->seedRunWithOutcomes();

        Sanctum::actingAs($this->employee);

        $list = $this->getJson('/api/billing-runs');
        $list->assertOk();
        $list->assertJsonPath('data.0.id', $run->id);
        $list->assertJsonPath('data.0.contracts_billed', 2);
        $list->assertJsonPath('data.0.contracts_skipped', 1);
        $list->assertJsonPath('data.0.contracts_failed', 1);
        $list->assertJsonPath('meta.total', 1);

        $totals = $list->json('data.0.totals_by_currency');
        $this->assertIsArray($totals);
        $this->assertCount(2, $totals);
        $this->assertSame('EUR', $totals[0]['currency']);
        $this->assertSame('100.00', $totals[0]['amount']);
        $this->assertSame('GBP', $totals[1]['currency']);
        $this->assertSame('50.00', $totals[1]['amount']);

        $show = $this->getJson("/api/billing-runs/{$run->id}");
        $show->assertOk();
        $show->assertJsonPath('data.id', $run->id);
        $this->assertCount(4, $show->json('data.items'));
        $show->assertJsonPath('data.items.0.contract.contact_name', 'Ada Lovelace');

        $failed = $this->getJson("/api/billing-runs/{$run->id}?outcome=failed");
        $failed->assertOk();
        $this->assertCount(1, $failed->json('data.items'));
        $failed->assertJsonPath('data.items.0.outcome', 'failed');
        $failed->assertJsonPath('data.items.0.detail', 'fiscal_blocker');

        $billed = $this->getJson("/api/billing-runs/{$run->id}?outcome=billed");
        $billed->assertOk();
        $this->assertCount(2, $billed->json('data.items'));
        $billed->assertJsonPath('data.items.0.outcome', 'billed');

        $emptyRun = BillingRun::query()->create([
            'started_at' => now(),
            'finished_at' => now(),
            'trigger' => BillingRunTrigger::Scheduled,
            'horizon_date' => '2026-08-15',
            'contracts_considered' => 0,
            'contracts_billed' => 0,
            'contracts_skipped' => 0,
            'contracts_failed' => 0,
            'created_by' => null,
            'created_at' => now(),
        ]);

        $emptyShow = $this->getJson("/api/billing-runs/{$emptyRun->id}");
        $emptyShow->assertOk();
        $emptyShow->assertJsonPath('data.contracts_considered', 0);
        $this->assertSame([], $emptyShow->json('data.items'));
        $this->assertSame([], $emptyShow->json('data.totals_by_currency'));
    }

    private function seedRunWithOutcomes(): BillingRun
    {
        $run = BillingRun::query()->create([
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'trigger' => BillingRunTrigger::Manual,
            'horizon_date' => '2026-08-15',
            'contracts_considered' => 3,
            'contracts_billed' => 1,
            'contracts_skipped' => 1,
            'contracts_failed' => 1,
            'created_by' => $this->employee->id,
            'created_at' => now(),
        ]);

        BillingRunItem::query()->create([
            'billing_run_id' => $run->id,
            'contract_id' => $this->contract->id,
            'outcome' => BillingRunItemOutcome::Billed,
            'periods_billed' => 1,
            'detail' => null,
            'error_message' => null,
            'invoice_ids' => [10],
            'amount_total' => '100.00',
            'currency' => 'EUR',
            'created_at' => now(),
        ]);

        // Second billed currency on a synthetic second contract for grouping.
        $gbpContract = Contract::factory()->create([
            'contact_id' => $this->contract->contact_id,
            'currency' => 'GBP',
            'status' => ContractStatus::Active,
            'billed_through' => '2026-08-15',
            'billing_anchor_date' => '2026-01-15',
            'start_date' => '2026-01-15',
            'move_in_date' => '2026-01-15',
        ]);

        BillingRunItem::query()->create([
            'billing_run_id' => $run->id,
            'contract_id' => $gbpContract->id,
            'outcome' => BillingRunItemOutcome::Billed,
            'periods_billed' => 1,
            'detail' => null,
            'error_message' => null,
            'invoice_ids' => [11],
            'amount_total' => '50.00',
            'currency' => 'GBP',
            'created_at' => now(),
        ]);

        // Adjust billed count to match two billed items for honesty in counters
        // while keeping a skipped + failed row for filter coverage.
        $run->forceFill(['contracts_billed' => 2, 'contracts_considered' => 4])->save();

        BillingRunItem::query()->create([
            'billing_run_id' => $run->id,
            'contract_id' => $this->contract->id,
            'outcome' => BillingRunItemOutcome::Skipped,
            'periods_billed' => 0,
            'detail' => 'not_due',
            'error_message' => null,
            'invoice_ids' => null,
            'amount_total' => null,
            'currency' => null,
            'created_at' => now(),
        ]);

        BillingRunItem::query()->create([
            'billing_run_id' => $run->id,
            'contract_id' => $this->contract->id,
            'outcome' => BillingRunItemOutcome::Failed,
            'periods_billed' => 0,
            'detail' => 'fiscal_blocker',
            'error_message' => 'Contact fiscal data incomplete.',
            'invoice_ids' => null,
            'amount_total' => null,
            'currency' => null,
            'created_at' => now(),
        ]);

        return $run->fresh();
    }
}
