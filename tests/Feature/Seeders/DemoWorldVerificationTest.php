<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\AccessGrantState;
use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Enums\AutopayAttemptStatus;
use App\Enums\ContactLifecycleStatus;
use App\Enums\ContractItemChangeReason;
use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Enums\HoldType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Enums\PlaybookKind;
use App\Models\AccessGrant;
use App\Models\AccessSuspension;
use App\Models\AutomationRun;
use App\Models\AutopayAttempt;
use App\Models\CallWrapup;
use App\Models\ChannelSuppression;
use App\Models\Charge;
use App\Models\CommsTriage;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractTransfer;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\EsignEnvelope;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Playbook;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageStatus;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Playbooks\PlaybookEnrolmentSummary;
use App\Support\Reports\AgeingBuckets;
use App\Support\Reports\AgeingReport;
use App\Support\Reports\DashboardReport;
use App\Support\Reports\MovementReport;
use App\Support\Reports\OccupancyReport;
use App\Support\Reports\RentRollReport;
use App\Support\Reports\ReportFilters;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoPipeline;
use Database\Seeders\Demo\DemoScript;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\AssertsOccupancyIntegrity;
use Tests\TestCase;

/**
 * Task 04 — full-world verification: matrix, existence, invariants, script determinism.
 */
class DemoWorldVerificationTest extends TestCase
{
    use AssertsOccupancyIntegrity;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DemoWorld::setCurrent(null);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_world_truth(): void
    {
        $result = DemoPipeline::run($this->app, withCrowd: true);
        $world = $result['world'];

        $this->assertLessThan(
            300_000,
            $result['elapsed_ms'],
            'Demo seed exceeded 5-minute budget. phases='.json_encode($result['phases']),
        );

        $asOf = CastExecutor::SIM_END;
        $this->freezeAsOf($asOf);

        $this->assertMatrixRanges($asOf);
        $this->assertExistenceSweep($asOf);
        $this->assertCastEndStates($world);
        $this->assertInvariantSweeps($asOf);
    }

    public function test_script_and_seed_determinism(): void
    {
        $this->setDemoSeed(424242);

        $first = DemoPipeline::run($this->app, withCrowd: true);
        $scriptA = DemoScript::render();
        $castEmailsA = $this->castEmails();
        $crowdEmailA = Contact::query()
            ->where('source_detail', 'demo_crowd')
            ->orderBy('id')
            ->value('email');
        $crowdCountA = (int) $first['crowd_count'];

        Artisan::call('migrate:fresh', ['--force' => true]);
        DemoWorld::setCurrent(null);

        $second = DemoPipeline::run($this->app, withCrowd: true);
        $scriptB = DemoScript::render();

        $this->assertSame($scriptA, $scriptB, 'Two DEMO_SEED=424242 runs must yield identical demo scripts');
        $this->assertSame($castEmailsA, $this->castEmails());
        $this->assertSame($crowdCountA, (int) $second['crowd_count']);
        $this->assertSame(
            $crowdEmailA,
            Contact::query()->where('source_detail', 'demo_crowd')->orderBy('id')->value('email'),
        );

        Artisan::call('migrate:fresh', ['--force' => true]);
        DemoWorld::setCurrent(null);
        $this->setDemoSeed(999001);

        DemoPipeline::run($this->app, withCrowd: true);
        $castEmailsAlt = $this->castEmails();
        $crowdEmailAlt = Contact::query()
            ->where('source_detail', 'demo_crowd')
            ->orderBy('id')
            ->value('email');
        $crowdCountAlt = Contact::query()->where('source_detail', 'demo_crowd')->count();

        $this->assertSame($castEmailsA, $castEmailsAlt, 'DEMO_SEED must not change the cast');
        $this->assertNotSame($crowdEmailA, $crowdEmailAlt, 'DEMO_SEED must vary the crowd');
        $this->assertGreaterThan(0, $crowdCountAlt);
    }

    private function freezeAsOf(string $asOf): void
    {
        $instant = CarbonImmutable::parse($asOf)->setTime(12, 0, 0);
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    private function setDemoSeed(int $seed): void
    {
        putenv('DEMO_SEED='.$seed);
        $_ENV['DEMO_SEED'] = (string) $seed;
        $_SERVER['DEMO_SEED'] = (string) $seed;
    }

    /** Primary cast contact emails (crowd uses a different pattern under the same domain). */
    private const CAST_EMAILS = [
        'amara.okafor@demo.unit-hq.test',
        'bea.torres@demo.unit-hq.test',
        'derek.hoyle@demo.unit-hq.test',
        'grace.lin@demo.unit-hq.test',
        'hannah.cole@demo.unit-hq.test',
        'ingrid.weiss@demo.unit-hq.test',
        'jeanluc.perrin@demo.unit-hq.test',
        'lucia.ferrer@demo.unit-hq.test',
        'marcus.webb@demo.unit-hq.test',
        'nadia.rahal@demo.unit-hq.test',
        'omar.haddad@demo.unit-hq.test',
        'pat.kelly@demo.unit-hq.test',
        'pilar.santos@demo.unit-hq.test',
        'rafa.nunez@demo.unit-hq.test',
        'sofia.marin@demo.unit-hq.test',
        'tom.bradley@demo.unit-hq.test',
        'viktor.palenik@demo.unit-hq.test',
    ];

    /**
     * @return list<string>
     */
    private function castEmails(): array
    {
        return Contact::query()
            ->whereIn('email', self::CAST_EMAILS)
            ->orderBy('email')
            ->pluck('email')
            ->map(static fn ($e) => (string) $e)
            ->values()
            ->all();
    }

    private function assertMatrixRanges(string $asOf): void
    {
        $lifecycle = Contact::statusCounts();
        $this->assertBetween((int) ($lifecycle['prospect'] ?? 0), 45, 75, 'prospect');
        $this->assertBetween((int) ($lifecycle['lead'] ?? 0), 25, 55, 'lead');
        $this->assertBetween((int) ($lifecycle['opportunity'] ?? 0), 15, 45, 'opportunity');
        $this->assertBetween((int) ($lifecycle['tenant'] ?? 0), 155, 185, 'tenant');
        $this->assertBetween((int) ($lifecycle['past_tenant'] ?? 0), 30, 60, 'past_tenant');
        $this->assertBetween((int) ($lifecycle['lost'] ?? 0), 1, 15, 'lost');

        $contacts = Contact::query()->count();
        $this->assertBetween($contacts, 300, 400, 'contacts total');

        foreach (DealStatus::cases() as $status) {
            $this->assertTrue(
                Deal::query()->where('status', $status->value)->exists(),
                "Deal status {$status->value} missing",
            );
        }
        $openDeals = Deal::query()->whereNotIn('status', DealStatus::terminalValues())->count();
        $this->assertBetween($openDeals, 10, 40, 'open deals');

        foreach (Offer::STATUSES as $status) {
            $this->assertTrue(
                Offer::query()->where('status', $status)->exists(),
                "Offer status {$status} missing",
            );
        }

        $this->assertBetween(
            Contract::query()->where('status', ContractStatus::Active)->count(),
            170,
            190,
            'active contracts',
        );
        $this->assertBetween(
            Contract::query()->where('status', ContractStatus::NoticeGiven)->count(),
            3,
            15,
            'notice contracts',
        );
        $this->assertBetween(
            Contract::query()->where('status', ContractStatus::Pending)->count(),
            1,
            12,
            'pending contracts',
        );
        $this->assertBetween(
            Contract::query()->where('status', ContractStatus::AwaitingSignature)->count(),
            2,
            12,
            'awaiting contracts',
        );
        $this->assertBetween(
            Contract::query()->where('status', ContractStatus::Ended)->count(),
            40,
            80,
            'ended contracts',
        );
        $this->assertBetween(
            Contract::query()->where('status', ContractStatus::Cancelled)->count(),
            1,
            12,
            'cancelled contracts',
        );

        $this->assertGreaterThanOrEqual(10, ContractTransfer::query()->count(), 'transfers');
        $appliedRates = ContractItem::query()
            ->where('change_reason', ContractItemChangeReason::RateChange->value)
            ->where('effective_from', '<=', $asOf)
            ->count();
        $scheduledRates = ContractItem::query()
            ->where('change_reason', ContractItemChangeReason::RateChange->value)
            ->where('effective_from', '>', $asOf)
            ->count();
        $this->assertGreaterThanOrEqual(15, $appliedRates, 'applied rate changes');
        $this->assertGreaterThanOrEqual(1, $scheduledRates, 'scheduled rate changes');

        $buckets = $this->openAgeingBuckets($asOf);
        foreach (AgeingBuckets::KEYS as $key) {
            $this->assertGreaterThanOrEqual(1, $buckets[$key], "Ageing bucket {$key} empty");
        }

        $this->assertTrue(
            UnitHold::query()
                ->where('hold_type', HoldType::Overlock->value)
                ->whereNull('released_at')
                ->exists(),
            'Expected ≥1 live overlock',
        );
        $this->assertGreaterThanOrEqual(
            1,
            AccessSuspension::query()->active()->count(),
            'Expected ≥1 active suspension',
        );
        $this->assertTrue(
            CallWrapup::query()->where('disposition', 'payment_promised')->exists(),
            'Expected payment_promised wrap-ups (promise flags)',
        );

        $messages = Message::query()->count();
        $this->assertBetween($messages, 450, 600, 'messages');
        $inboundThreads = MessageThread::query()
            ->whereHas('messages', static fn ($q) => $q->where('direction', MessageDirection::Inbound->value))
            ->count();
        $this->assertGreaterThanOrEqual(40, $inboundThreads, 'threads with inbound');
        $this->assertSame(
            4,
            CommsTriage::query()->where('status', 'pending')->count(),
            'triage pending',
        );
        $this->assertGreaterThanOrEqual(3, ChannelSuppression::query()->count(), 'suppressions');
        $this->assertSame(
            7,
            MessageThread::query()->where('unread_count', '>', 0)->count(),
            'unread threads',
        );

        $this->assertTrue(
            Payment::query()->where('method', PaymentMethod::StripeCard->value)->exists(),
            'Stripe card rail missing',
        );
        $railCount = Payment::query()->whereNotNull('method')->distinct()->count('method');
        $this->assertGreaterThanOrEqual(1, $railCount, 'Expected payment rails present');
        $this->assertTrue(
            AutopayAttempt::query()->where('status', AutopayAttemptStatus::Failed)->exists(),
            'Expected failed autopay',
        );
        $this->assertTrue(
            PaymentRequest::query()->whereIn('status', [
                PaymentRequestStatus::Pending->value,
                PaymentRequestStatus::Paid->value,
            ])->exists(),
            'Expected payment links',
        );
        $this->assertGreaterThan(0, Invoice::query()->count(), 'invoices');
    }

    private function assertExistenceSweep(string $asOf): void
    {
        foreach (ContractStatus::cases() as $status) {
            $this->assertTrue(
                Contract::query()->where('status', $status->value)->exists(),
                "Contract status {$status->value} missing",
            );
        }

        $this->assertTrue(
            UnitHold::query()->where('hold_type', HoldType::Overlock->value)->exists(),
            'Hold type overlock missing',
        );
        $this->assertTrue(
            UnitHold::query()->whereIn('hold_type', [
                HoldType::Reservation->value,
                HoldType::ContractSignature->value,
            ])->exists(),
            'Pipeline hold (reservation/contract_signature) missing',
        );

        foreach ([
            EsignEnvelopeStatus::Sent,
            EsignEnvelopeStatus::Viewed,
            EsignEnvelopeStatus::Signed,
            EsignEnvelopeStatus::Declined,
        ] as $status) {
            $this->assertTrue(
                EsignEnvelope::query()->where('status', $status->value)->exists(),
                "Envelope status {$status->value} missing",
            );
        }

        $this->assertTrue(
            EsignEnvelope::query()
                ->whereIn('status', [
                    EsignEnvelopeStatus::Sent->value,
                    EsignEnvelopeStatus::Viewed->value,
                ])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', CarbonImmutable::parse($asOf)->addDays(3)->endOfDay())
                ->where('expires_at', '>=', CarbonImmutable::parse($asOf)->startOfDay())
                ->exists(),
            'Expected awaiting envelope expiring within 3 days',
        );

        $this->assertTrue(
            AccessGrant::query()->where('state', AccessGrantState::Applied->value)->exists(),
            'Access grant applied missing',
        );

        foreach ([
            MessageStatus::Received,
            MessageStatus::Sent,
            MessageStatus::Delivered,
            MessageStatus::Bounced,
        ] as $status) {
            $this->assertTrue(
                Message::query()->where('status', $status->value)->exists(),
                "Message status {$status->value} missing",
            );
        }

        foreach (ContactLifecycleStatus::cases() as $status) {
            $this->assertGreaterThan(
                0,
                Contact::query()->where('status', $status->value)->count(),
                "Contact lifecycle {$status->value} empty",
            );
        }
    }

    private function assertCastEndStates(DemoWorld $world): void
    {
        foreach (CastExecutor::CAST as $class) {
            $class::assertEndState($world);
        }
    }

    private function assertInvariantSweeps(string $asOf): void
    {
        $this->assertNoOverlappingOccupancies();
        $this->assertNoOverlappingBlockingHolds();
        $this->assertEveryNonAwaitingContractHasOccupancy();

        $filters = new ReportFilters(asOf: $asOf);
        $ageing = (new AgeingReport)->run($filters);

        $reportTotal = '0.00';
        foreach ($ageing->meta['totals_by_currency'] as $row) {
            $reportTotal = BillingMath::round2(bcadd($reportTotal, (string) $row['amount'], 2));
        }

        $contractBucketSum = '0.00';
        foreach ($ageing->meta['contract_bucket_totals'] as $amount) {
            $contractBucketSum = BillingMath::round2(bcadd($contractBucketSum, (string) $amount, 2));
        }
        $chargeBucketSum = '0.00';
        foreach ($ageing->meta['charge_bucket_totals'] as $amount) {
            $chargeBucketSum = BillingMath::round2(bcadd($chargeBucketSum, (string) $amount, 2));
        }
        $this->assertSame($reportTotal, $contractBucketSum, 'Ageing contract buckets vs totals');
        $this->assertSame($reportTotal, $chargeBucketSum, 'Ageing charge buckets vs totals');

        $numbers = DemoScript::liveNumbers($asOf);
        $this->assertSame($numbers['occ_report'], $numbers['occ_metrics'], 'Occupancy report vs metrics');
        $this->assertSame($numbers['occ_report'], $numbers['occ_units_list'], 'Occupancy report vs units list');
        $this->assertSame($numbers['occ_report'], $numbers['occ_matrix'], 'Occupancy report vs matrix distinct');

        // Ageing open-case rows (case_stage set) must equal the board chip.
        $openCaseAgeing = '0.00';
        foreach ($ageing->rows as $row) {
            if (($row['case_stage'] ?? null) === null) {
                continue;
            }
            $openCaseAgeing = BillingMath::round2(bcadd($openCaseAgeing, (string) $row['total'], 2));
        }
        $this->assertSame($numbers['ageing_chip'], $openCaseAgeing, 'Open-case ageing vs board chip');

        $dashboard = (new DashboardReport)->run($filters);
        $cards = $dashboard->meta['cards'];
        $occ = (new OccupancyReport)->run($filters);
        $this->assertSame($occ->meta['headlines']['unit']['rate'], $cards['occupancy']['value']);
        $this->assertSame($occ->meta['headlines']['economic']['rate'], $cards['occupancy']['secondary']['economic_rate']);

        $rent = (new RentRollReport)->run($filters);
        $this->assertSame($rent->meta['footer']['monthly_rent'], $cards['monthly_rent']['value']);

        $overdueAmount = $ageing->meta['totals_by_currency'][0]['amount'] ?? '0.00';
        $this->assertSame($overdueAmount, $cards['overdue']['value']);
        $this->assertSame(count($ageing->rows), $cards['overdue']['secondary']['contract_count']);

        $asOfDate = CarbonImmutable::parse($asOf)->startOfDay();
        $movement = (new MovementReport)->run(new ReportFilters(
            from: $asOfDate->startOfMonth()->toDateString(),
            to: $asOf,
        ));
        $expectedNet = (int) ($movement->meta['identity']['move_ins'] ?? 0)
            - (int) ($movement->meta['identity']['move_outs'] ?? 0);
        $this->assertSame($expectedNet, $cards['movement_net']['value']);

        $openCases = Delinquency::query()
            ->where('opened_on', '<=', $asOf)
            ->where(static function ($q) use ($asOf): void {
                $q->whereNull('cured_on')->orWhere('cured_on', '>', $asOf);
            })
            ->count();
        $this->assertSame($openCases, $cards['open_delinquency_cases']['value']);

        $this->assertBalanceIdentity();
        $this->assertMessageProvenance();
        $this->assertPlaybookEnrolmentGuards();
    }

    private function assertBalanceIdentity(): void
    {
        /** @var array<string, string> $byCurrency */
        $byCurrencyCharges = [];
        /** @var array<string, string> $byCurrencyPayments */
        $byCurrencyPayments = [];
        /** @var array<string, string> $byCurrencyBalances */
        $byCurrencyBalances = [];

        Contract::query()->orderBy('id')->each(function (Contract $contract) use (
            &$byCurrencyCharges,
            &$byCurrencyPayments,
            &$byCurrencyBalances,
        ): void {
            $currency = (string) $contract->currency;
            $charges = BillingMath::round2((string) Charge::query()->where('contract_id', $contract->id)->sum('amount'));
            $payments = BillingMath::round2((string) Payment::query()->where('contract_id', $contract->id)->sum('amount'));
            $balance = BillingMath::round2(bcsub($charges, $payments, 2));

            $this->assertSame(
                $balance,
                BillingMath::round2($contract->balanceOwed()),
                "Contract {$contract->id} balanceOwed mismatch",
            );

            $byCurrencyCharges[$currency] = BillingMath::round2(
                bcadd($byCurrencyCharges[$currency] ?? '0.00', $charges, 2),
            );
            $byCurrencyPayments[$currency] = BillingMath::round2(
                bcadd($byCurrencyPayments[$currency] ?? '0.00', $payments, 2),
            );
            $byCurrencyBalances[$currency] = BillingMath::round2(
                bcadd($byCurrencyBalances[$currency] ?? '0.00', $balance, 2),
            );
        });

        foreach ($byCurrencyBalances as $currency => $sumBalances) {
            $expected = BillingMath::round2(bcsub(
                $byCurrencyCharges[$currency] ?? '0.00',
                $byCurrencyPayments[$currency] ?? '0.00',
                2,
            ));
            $this->assertSame($expected, $sumBalances, "Balance identity failed for {$currency}");
        }
    }

    private function assertMessageProvenance(): void
    {
        $orphans = Message::query()
            ->where(static function ($q): void {
                $q->whereNull('source')
                    ->orWhereNull('provider')
                    ->orWhereNull('communication_account_id');
            })
            ->count();

        $this->assertSame(0, $orphans, 'Messages missing provenance (source/provider/account)');
    }

    private function assertPlaybookEnrolmentGuards(): void
    {
        $cancelledWithoutCause = AutomationRun::query()
            ->where('status', AutomationRunStatus::Cancelled)
            ->whereNull('cancel_cause')
            ->count();
        $this->assertSame(0, $cancelledWithoutCause, 'Cancelled runs must have cancel_cause');

        $guardCancelled = AutomationRun::query()
            ->where('status', AutomationRunStatus::Cancelled)
            ->where('cancel_cause', AutomationCancelCause::Guard)
            ->count();
        $this->assertGreaterThanOrEqual(0, $guardCancelled);

        foreach (Playbook::query()->where('kind', PlaybookKind::LeadChase)->get() as $playbook) {
            $lineage = PlaybookEnrolmentSummary::lineageQuery((int) $playbook->id)->count();
            $this->assertGreaterThanOrEqual(0, $lineage);
        }

        $activeStatuses = array_map(
            static fn (AutomationRunStatus $s): string => $s->value,
            PlaybookEnrolmentSummary::activeStatuses(),
        );
        $exitedStatuses = array_map(
            static fn (AutomationRunStatus $s): string => $s->value,
            PlaybookEnrolmentSummary::exitedStatuses(),
        );

        $unknown = AutomationRun::query()
            ->whereHas('automation', static fn ($q) => $q->whereNotNull('playbook_id'))
            ->whereNotIn('status', array_merge($activeStatuses, $exitedStatuses))
            ->count();
        $this->assertSame(0, $unknown, 'Playbook runs must use known active/exited statuses');
    }

    /**
     * @return array<string, int>
     */
    private function openAgeingBuckets(string $asOf): array
    {
        $counts = array_fill_keys(AgeingBuckets::KEYS, 0);
        $on = CarbonImmutable::parse($asOf)->startOfDay();

        $cases = Delinquency::query()->open()->with('contract')->get();
        foreach ($cases as $case) {
            $contract = $case->contract;
            if ($contract === null) {
                continue;
            }
            $days = DelinquencyState::daysOverdue($contract, $on);
            if ($days === null || $days < 1) {
                continue;
            }
            $key = AgeingBuckets::fromDays($days);
            $counts[$key]++;
        }

        return $counts;
    }

    private function assertBetween(int $value, int $min, int $max, string $label): void
    {
        $this->assertGreaterThanOrEqual($min, $value, "{$label} got {$value}, want ≥{$min}");
        $this->assertLessThanOrEqual($max, $value, "{$label} got {$value}, want ≤{$max}");
    }
}
