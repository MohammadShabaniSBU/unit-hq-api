<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Billing\BillingMath;
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Reports\AgeingReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class AgeingTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private int $priceId;

    private DelinquencyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();

        $this->policy = DelinquencyPolicy::factory()->create(['name' => 'ageing-policy']);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 3,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 1,
        ]);

        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'name' => 'Madrid Hub',
            'delinquency_policy_id' => $this->policy->id,
        ]);

        $this->unitClass = UnitClass::factory()->create([
            'code' => 'S10',
            'label' => 'Small 10',
            'size' => '10.00',
        ]);
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = (int) $price->id;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_buckets_boundaries_reconcile_board(): void
    {
        // Boundary days past due on as_of 2026-08-20.
        $boundaries = [
            ['days' => 1, 'due' => '2026-08-19', 'bucket' => '1-7', 'amount' => '10.00'],
            ['days' => 7, 'due' => '2026-08-13', 'bucket' => '1-7', 'amount' => '20.00'],
            ['days' => 8, 'due' => '2026-08-12', 'bucket' => '8-14', 'amount' => '30.00'],
            ['days' => 14, 'due' => '2026-08-06', 'bucket' => '8-14', 'amount' => '40.00'],
            ['days' => 15, 'due' => '2026-08-05', 'bucket' => '15-30', 'amount' => '50.00'],
            ['days' => 30, 'due' => '2026-07-21', 'bucket' => '15-30', 'amount' => '60.00'],
            ['days' => 31, 'due' => '2026-07-20', 'bucket' => '31-60', 'amount' => '70.00'],
            ['days' => 60, 'due' => '2026-06-21', 'bucket' => '31-60', 'amount' => '80.00'],
            ['days' => 61, 'due' => '2026-06-20', 'bucket' => '60+', 'amount' => '90.00'],
        ];

        $expectedTotal = '0.00';
        foreach ($boundaries as $i => $spec) {
            $this->seedOpenCase(
                unitNumber: 'B-'.$i,
                dueDate: $spec['due'],
                amount: $spec['amount'],
                chargeType: ChargeType::Rent,
            );
            $expectedTotal = bcadd($expectedTotal, $spec['amount'], 2);
        }
        $expectedTotal = BillingMath::round2($expectedTotal);

        // Multi-charge contract: oldest unpaid lands in 60+, younger fee in 1-7.
        $multi = $this->seedOpenCase(
            unitNumber: 'M-1',
            dueDate: '2026-06-20',
            amount: '100.00',
            chargeType: ChargeType::Rent,
        );
        Charge::factory()->create([
            'contract_id' => $multi->contract_id,
            'charge_type' => ChargeType::LateFee,
            'amount' => '15.00',
            'net_amount' => '15.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-19',
        ]);
        DelinquencyStep::query()->create([
            'delinquency_id' => $multi->id,
            'policy_step_id' => null,
            'action' => DelinquencyStepAction::RecordNotice,
            'executed_on' => '2026-08-10',
            'trigger' => DelinquencyStepTrigger::Manual,
            'created_by' => $this->employee->id,
        ]);
        $expectedTotal = BillingMath::round2(bcadd($expectedTotal, '115.00', 2));

        $report = new AgeingReport;
        $result = $report->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            asOf: '2026-08-20',
        ));

        $this->assertSame('2026-08-20', $result->meta['as_of']);
        $this->assertSame($expectedTotal, $result->meta['totals_by_currency'][0]['amount']);
        $this->assertSame('EUR', $result->meta['totals_by_currency'][0]['currency']);

        $contractBucketSum = '0.00';
        foreach ($result->meta['contract_bucket_totals'] as $amount) {
            $contractBucketSum = bcadd($contractBucketSum, $amount, 2);
        }
        $this->assertSame($expectedTotal, BillingMath::round2($contractBucketSum));

        $chargeBucketSum = '0.00';
        foreach ($result->meta['charge_bucket_totals'] as $amount) {
            $chargeBucketSum = bcadd($chargeBucketSum, $amount, 2);
        }
        $this->assertSame($expectedTotal, BillingMath::round2($chargeBucketSum));

        $chargeViewSum = '0.00';
        foreach ($result->meta['charge_view'] as $row) {
            $chargeViewSum = bcadd($chargeViewSum, $row['amount'], 2);
        }
        $this->assertSame($expectedTotal, BillingMath::round2($chargeViewSum));

        foreach ($boundaries as $i => $spec) {
            $row = collect($result->rows)->firstWhere('unit_number', 'B-'.$i);
            $this->assertNotNull($row, "Missing row for unit B-{$i}");
            $this->assertSame($spec['days'], $row['days_overdue']);
            $this->assertSame($spec['bucket'], $row['bucket']);
            $this->assertSame($spec['amount'], $row['total']);
        }

        $multiRow = collect($result->rows)->firstWhere('unit_number', 'M-1');
        $this->assertNotNull($multiRow);
        $this->assertSame(61, $multiRow['days_overdue']);
        $this->assertSame('60+', $multiRow['bucket']);
        $this->assertSame('100.00', $multiRow['rent']);
        $this->assertSame('15.00', $multiRow['fees']);
        $this->assertSame('115.00', $multiRow['total']);
        $this->assertSame('115.00', $multiRow['bucket_60_plus']);
        $this->assertSame('record_notice', $multiRow['last_step']);
        $this->assertSame('open', $multiRow['case_stage']);

        // Charge view puts the late fee in 1-7 and rent in 60+.
        $feeIn17 = collect($result->meta['charge_view'])->first(
            static fn (array $r): bool => $r['bucket'] === '1-7' && $r['charge_type'] === 'late_fee',
        );
        $this->assertNotNull($feeIn17);
        $this->assertSame('15.00', $feeIn17['amount']);

        $board = $this->getJson('/api/delinquencies?site_id='.$this->site->id.'&per_page=100');
        $board->assertOk();
        $chip = collect($board->json('meta.overdue_by_currency'))
            ->firstWhere('currency', 'EUR');
        $this->assertNotNull($chip);
        $this->assertSame($expectedTotal, $chip['amount']);

        $api = $this->getJson('/api/reports/ageing?as_of=2026-08-20&site_ids[]='.$this->site->id);
        $api->assertOk();
        $api->assertJsonPath('data.meta.totals_by_currency.0.amount', $expectedTotal);
    }

    private function seedOpenCase(
        string $unitNumber,
        string $dueDate,
        string $amount,
        ChargeType $chargeType,
    ): Delinquency {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $unitNumber,
            'enabled' => true,
        ]);

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-01-01',
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => $chargeType,
            'amount' => $amount,
            'net_amount' => $amount,
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => $dueDate,
        ]);

        $contract = $contract->fresh(['unitItem.item.site', 'charges.allocations']) ?? $contract;

        return DelinquencyLifecycle::openOrFail($contract);
    }
}
