<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\BillingRunTrigger;
use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Models\BillingRun;
use App\Models\Charge;
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
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ManualRunTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_causer_and_dry_run(): void
    {
        $this->postJson('/api/billing-runs')->assertUnauthorized();

        $contract = $this->makeBillableContract(billedThrough: '2026-07-15');
        $chargesBefore = Charge::query()->count();

        Sanctum::actingAs($this->employee);

        $dry = $this->postJson('/api/billing-runs', ['dry_run' => true]);
        $dry->assertOk();
        $dry->assertJsonPath('data.0.contract_id', $contract->id);
        $this->assertGreaterThan(0, $dry->json('data.0.periods'));

        $this->assertSame(0, BillingRun::query()->count());
        $this->assertSame($chargesBefore, Charge::query()->count());
        $contract->refresh();
        $this->assertSame('2026-07-15', $contract->billedThrough());

        $real = $this->postJson('/api/billing-runs', ['dry_run' => false]);
        $real->assertCreated();
        $runId = $real->json('data.id');
        $this->assertNotNull($runId);

        $run = BillingRun::query()->findOrFail($runId);
        $this->assertSame(BillingRunTrigger::Manual, $run->trigger);
        $this->assertSame($this->employee->id, $run->created_by);
        $this->assertSame(1, $run->contracts_billed);

        $activity = Activity::query()
            ->where('log_name', LogChannel::Billing->value)
            ->where('description', 'billing.run.completed')
            ->where('subject_id', $run->id)
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame($this->employee->id, $activity->causer_id);
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
