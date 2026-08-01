<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\BillingRunItemOutcome;
use App\Enums\BillingRunTrigger;
use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Models\BillingRun;
use App\Models\BillingRunItem;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Billing\BillingRunEngine;
use App\Support\Billing\PeriodResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class RunEngineTest extends TestCase
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

        // August — matches system_events monthly partitions created at migrate time.
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rerun_is_noop(): void
    {
        $contract = $this->makeBillableContract(billedThrough: '2026-07-15');

        $engine = new BillingRunEngine;
        $first = $engine->run(BillingRunTrigger::Manual);
        $this->assertSame(1, $first->contracts_billed);
        $this->assertSame(0, $first->contracts_skipped);

        $contract->refresh();
        $cursorAfterFirst = $contract->billedThrough();
        // Inclusive horizon bills Jul→Aug and Aug→Sep; cursor lands past horizon.
        $this->assertSame('2026-09-15', $cursorAfterFirst);

        $second = $engine->run(BillingRunTrigger::Manual);
        $this->assertSame(0, $second->contracts_billed);
        $this->assertSame(0, $second->contracts_failed);
        // Cursor past horizon ⇒ not eligible; run row only, no billable writes.
        $this->assertSame(0, $second->contracts_considered);
        $this->assertSame(
            0,
            BillingRunItem::query()->where('billing_run_id', $second->id)->count()
        );

        $contract->refresh();
        $this->assertSame($cursorAfterFirst, $contract->billedThrough());

        $this->assertSame(2, BillingRun::query()->count());
        $this->assertSame(1, BillingRunItem::query()->count());
    }

    public function test_failure_isolated_and_recorded(): void
    {
        $poisoned = $this->makeBillableContract(billedThrough: '2026-07-15');
        $healthy = $this->makeBillableContract(billedThrough: '2026-07-15');
        $poisonedCursor = $poisoned->billedThrough();

        $engine = new BillingRunEngine(
            generator: function (Contract $c) use ($poisoned) {
                if ($c->id === $poisoned->id) {
                    throw new \RuntimeException('poisoned stub');
                }

                return PeriodResult::empty();
            },
        );

        $run = $engine->run(BillingRunTrigger::Manual);

        $this->assertSame(1, $run->contracts_billed);
        $this->assertSame(1, $run->contracts_failed);
        $this->assertSame(0, $run->contracts_skipped);

        $failedItem = BillingRunItem::query()
            ->where('billing_run_id', $run->id)
            ->where('contract_id', $poisoned->id)
            ->first();
        $this->assertNotNull($failedItem);
        $this->assertSame(BillingRunItemOutcome::Failed, $failedItem->outcome);
        $this->assertSame('error', $failedItem->detail);
        $this->assertStringContainsString('poisoned stub', (string) $failedItem->error_message);

        $billedItem = BillingRunItem::query()
            ->where('billing_run_id', $run->id)
            ->where('contract_id', $healthy->id)
            ->first();
        $this->assertNotNull($billedItem);
        $this->assertSame(BillingRunItemOutcome::Billed, $billedItem->outcome);

        $poisoned->refresh();
        $this->assertSame($poisonedCursor, $poisoned->billedThrough());

        $healthy->refresh();
        $this->assertNotSame('2026-07-15', $healthy->billedThrough());

        $this->assertTrue(
            SystemEvent::query()
                ->where('event', 'billing.contract.failed')
                ->where('subject_id', $poisoned->id)
                ->exists()
        );

        $this->assertDatabaseHas('activity_log', [
            'log_name' => LogChannel::Billing->value,
            'description' => 'billing.run.completed',
        ]);
    }

    public function test_status_recheck_after_lock(): void
    {
        $contract = $this->makeBillableContract(billedThrough: '2026-07-15');
        $cursorBefore = $contract->billedThrough();

        $engine = new BillingRunEngine(
            beforeLock: function (int $id) use ($contract) {
                $this->assertSame($contract->id, $id);
                Contract::query()->whereKey($id)->update([
                    'status' => ContractStatus::Ended->value,
                ]);
            },
        );

        $run = $engine->run(BillingRunTrigger::Manual);

        $this->assertSame(0, $run->contracts_billed);
        $this->assertSame(1, $run->contracts_skipped);

        $item = BillingRunItem::query()->where('billing_run_id', $run->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(BillingRunItemOutcome::Skipped, $item->outcome);
        $this->assertSame('ineligible_status', $item->detail);

        $contract->refresh();
        $this->assertSame($cursorBefore, $contract->billedThrough());
        $this->assertSame(ContractStatus::Ended, $contract->status);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $contract = $this->makeBillableContract(billedThrough: '2026-07-15');
        $cursorBefore = $contract->billedThrough();

        $runsBefore = BillingRun::query()->count();
        $itemsBefore = BillingRunItem::query()->count();

        $exit = Artisan::call('billing:run', ['--dry-run' => true]);
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString((string) $contract->id, $output);
        $this->assertStringContainsString('periods', $output);

        $this->assertSame($runsBefore, BillingRun::query()->count());
        $this->assertSame($itemsBefore, BillingRunItem::query()->count());

        $contract->refresh();
        $this->assertSame($cursorBefore, $contract->billedThrough());
    }

    public function test_cap_fails_cleanly(): void
    {
        config(['billing.catch_up_cap' => 1]);

        // Cursor far enough that >1 monthly periods are due before 2026-08-15.
        $contract = $this->makeBillableContract(billedThrough: '2026-05-15');
        $cursorBefore = $contract->billedThrough();

        $run = (new BillingRunEngine)->run(BillingRunTrigger::Manual);

        $this->assertSame(0, $run->contracts_billed);
        $this->assertSame(1, $run->contracts_failed);

        $item = BillingRunItem::query()->where('billing_run_id', $run->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(BillingRunItemOutcome::Failed, $item->outcome);
        $this->assertSame('catch_up_cap', $item->detail);

        $contract->refresh();
        $this->assertSame($cursorBefore, $contract->billedThrough());
    }

    public function test_stop_line_precheck_skips_without_cursor_write(): void
    {
        $contract = $this->makeBillableContract(billedThrough: '2026-08-15');
        $contract->forceFill([
            'status' => ContractStatus::NoticeGiven,
            'notice_given_on' => '2026-07-01',
            'notice_period_days' => 14,
            'scheduled_move_out_on' => '2026-07-20',
        ])->save();
        $cursorBefore = $contract->billedThrough();

        // Inject stub so a missing generator body cannot mask the pre-check.
        $run = (new BillingRunEngine(
            generator: fn () => PeriodResult::empty(),
        ))->run(BillingRunTrigger::Manual);

        $this->assertSame(0, $run->contracts_billed);
        $this->assertSame(1, $run->contracts_skipped);

        $item = BillingRunItem::query()->where('billing_run_id', $run->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(BillingRunItemOutcome::Skipped, $item->outcome);
        $this->assertSame('stop_line', $item->detail);

        $contract->refresh();
        $this->assertSame($cursorBefore, $contract->billedThrough());
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
