<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\ContractStatus;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractTransfer;
use App\Models\Delinquency;
use App\Models\UnitOccupancy;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Reports\AgeingBuckets;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoPipeline;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\ScheduleJobRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Task 02 — full cast+crowd simulation: matrix bands, determinism, trends, budget.
 */
class SimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DemoWorld::setCurrent(null);
        parent::tearDown();
    }

    public function test_schedule_job_runner_matches_bootstrap_order(): void
    {
        ScheduleJobRunner::assertMatchesSchedule();
        $this->assertSame(
            [
                'contracts:activate',
                'billing:run --trigger=scheduled',
                'autopay:collect --trigger=sweep',
                'delinquency:run',
                'access:sync',
                'automations:resume-waiting',
            ],
            ScheduleJobRunner::COMMANDS,
        );
    }

    public function test_matrix_bands(): void
    {
        $result = DemoPipeline::run($this->app, withCrowd: true);
        $this->assertRuntimeBudget($result['elapsed_ms'], $result['phases']);

        $contacts = Contact::query()->count();
        $active = Contract::query()->where('status', ContractStatus::Active)->count();
        $ended = Contract::query()->where('status', ContractStatus::Ended)->count();
        $transfers = ContractTransfer::query()->count();

        $this->assertGreaterThanOrEqual(250, $contacts, "contacts got {$contacts}");
        $this->assertLessThanOrEqual(400, $contacts, "contacts got {$contacts}");
        $this->assertGreaterThanOrEqual(140, $active, "active contracts got {$active}");
        $this->assertLessThanOrEqual(220, $active, "active contracts got {$active}");
        $this->assertGreaterThanOrEqual(30, $ended, "ended contracts got {$ended}");
        $this->assertGreaterThanOrEqual(8, $transfers, "transfers got {$transfers}");

        $this->assertTrue(
            Contract::query()->where('status', ContractStatus::Pending)->exists()
            || Contract::query()->where('status', ContractStatus::AwaitingSignature)->exists(),
            'Expected pending or awaiting-signature contracts from cast',
        );
    }

    public function test_determinism_twice(): void
    {
        putenv('DEMO_SEED=424242');
        $_ENV['DEMO_SEED'] = '424242';
        $_SERVER['DEMO_SEED'] = '424242';

        $first = $this->worldSnapshot(DemoPipeline::run($this->app, withCrowd: true));

        Artisan::call('migrate:fresh', ['--force' => true]);
        DemoWorld::setCurrent(null);

        $second = $this->worldSnapshot(DemoPipeline::run($this->app, withCrowd: true));

        $this->assertSame($first, $second, 'Two DEMO_SEED=424242 runs must match counts/spot checks');
    }

    public function test_trend_and_buckets(): void
    {
        $result = DemoPipeline::run($this->app, withCrowd: true);
        $this->assertRuntimeBudget($result['elapsed_ms'], $result['phases']);

        $curve = $this->occupancyCurve();
        $this->assertGreaterThanOrEqual(6, count($curve), 'Need monthly occupancy samples');

        $values = array_values($curve);
        $earlyAvg = (int) round(array_sum(array_slice($values, 0, 3)) / 3);
        $lateAvg = (int) round(array_sum(array_slice($values, -3)) / 3);
        $this->assertGreaterThan(
            $earlyAvg,
            0,
            'Early occupancy should be non-zero: '.json_encode($curve),
        );
        $this->assertGreaterThanOrEqual(
            $lateAvg,
            $earlyAvg,
            'Occupancy trend should rise: earlyAvg='.$earlyAvg.' lateAvg='.$lateAvg.' curve='.json_encode($curve),
        );
        $this->assertGreaterThanOrEqual(
            $values[array_key_last($values)],
            $values[0],
            'Seed-end occupancy should exceed first-month occupancy',
        );

        $buckets = $this->openAgeingBuckets();
        foreach (AgeingBuckets::KEYS as $key) {
            $this->assertArrayHasKey($key, $buckets, "Missing ageing bucket {$key}");
            $this->assertGreaterThanOrEqual(1, $buckets[$key], "Ageing bucket {$key} empty");
        }

        $moveOutMonths = Contract::query()
            ->where('status', ContractStatus::Ended)
            ->whereNotNull('move_out_on')
            ->get(['move_out_on'])
            ->map(static fn (Contract $c): string => CarbonImmutable::parse((string) $c->move_out_on)->format('Y-m'))
            ->unique()
            ->count();
        $this->assertGreaterThanOrEqual(6, $moveOutMonths, 'Churn should spread across the year');
    }

    /**
     * @param  array{cast_crowd: float, jobs: float, standing: float, texture: float}  $phases
     */
    private function assertRuntimeBudget(float $elapsedMs, array $phases): void
    {
        $this->assertLessThan(
            300_000,
            $elapsedMs,
            'Demo seed exceeded 5-minute budget. phases='.json_encode($phases),
        );
    }

    /**
     * @return array<string, int|string|float>
     */
    private function worldSnapshot(array $result): array
    {
        return [
            'contacts' => Contact::query()->count(),
            'contracts' => Contract::query()->count(),
            'active' => Contract::query()->where('status', ContractStatus::Active)->count(),
            'ended' => Contract::query()->where('status', ContractStatus::Ended)->count(),
            'transfers' => ContractTransfer::query()->count(),
            'charges_sum' => (string) Charge::query()->sum('amount'),
            'delinquencies' => Delinquency::query()->count(),
            'crowd_count' => $result['crowd_count'],
            'first_crowd_email' => Contact::query()
                ->where('source_detail', 'demo_crowd')
                ->orderBy('id')
                ->value('email'),
            'last_active_id' => Contract::query()
                ->where('status', ContractStatus::Active)
                ->orderByDesc('id')
                ->value('id'),
        ];
    }

    /**
     * Reconstruct month-end occupied unit counts across the sim window.
     *
     * @return array<string, int>  Y-m => occupied units
     */
    private function occupancyCurve(): array
    {
        $start = CarbonImmutable::parse(CastExecutor::SIM_START)->startOfMonth();
        $end = CarbonImmutable::parse(CastExecutor::SIM_END)->startOfMonth();
        $curve = [];

        $cursor = $start;
        while ($cursor->lte($end)) {
            $asOf = $cursor->endOfMonth()->toDateString();
            if ($cursor->format('Y-m') === CarbonImmutable::parse(CastExecutor::SIM_END)->format('Y-m')) {
                $asOf = CastExecutor::SIM_END;
            }

            $occupied = UnitOccupancy::query()
                ->where('started_on', '<=', $asOf)
                ->where(static function ($q) use ($asOf): void {
                    $q->whereNull('ended_on')->orWhere('ended_on', '>', $asOf);
                })
                ->count();

            $curve[$cursor->format('Y-m')] = $occupied;
            $cursor = $cursor->addMonth();
        }

        return $curve;
    }

    /**
     * @return array<string, int>
     */
    private function openAgeingBuckets(): array
    {
        $counts = array_fill_keys(AgeingBuckets::KEYS, 0);

        $cases = Delinquency::query()->open()->with('contract')->get();
        foreach ($cases as $case) {
            $contract = $case->contract;
            if ($contract === null) {
                continue;
            }
            $days = DelinquencyState::daysOverdue($contract);
            if ($days === null || $days < 1) {
                continue;
            }
            $key = AgeingBuckets::fromDays($days);
            $counts[$key]++;
        }

        return $counts;
    }
}
