<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\AutomationRunStatus;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\AutomationRun;
use App\Models\CallWrapup;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Delinquency\DelinquencyState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Delinquency ageing: contract view (oldest-unpaid bucket) + charge-view totals.
 * Definitions: docs/report-definitions.md — Ageing section.
 */
final class AgeingReport extends AbstractReport
{
    public static function name(): string
    {
        return 'ageing';
    }

    public function maxQueries(): int
    {
        return 40;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = OccupancyMetrics::resolveAsOf($filters);
        $on = CarbonImmutable::parse($asOf)->startOfDay();
        $promiseSince = $on->subDays(AgeingBuckets::PROMISE_WINDOW_DAYS)->startOfDay();

        $triggerValues = array_map(
            static fn (ChargeType $t): string => $t->value,
            DelinquencyState::TRIGGER_TYPES,
        );

        $contracts = Contract::query()
            ->with([
                'contact:id,first_name,last_name',
                'charges.allocations',
                'payments' => static fn ($q) => $q->orderByDesc('id'),
                'unitItem.item' => static function ($morphTo): void {
                    $morphTo->morphWith([
                        Unit::class => ['site:id,name,timezone,currency'],
                    ]);
                },
                'delinquencies' => static fn ($q) => $q->whereNull('cured_on')->with(['steps', 'policy:id,name']),
            ])
            ->whereIn('status', [
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
                ContractStatus::Pending->value,
                ContractStatus::Ended->value,
            ])
            ->whereHas('charges', static function (Builder $q) use ($asOf, $triggerValues): void {
                $q->where('due_date', '<', $asOf)
                    ->whereIn('charge_type', $triggerValues);
            })
            ->when($filters->siteIds !== null, function (Builder $q) use ($filters): void {
                $q->whereHas('unitItem', function (Builder $item) use ($filters): void {
                    $item->where('item_type', 'unit')
                        ->whereIn('item_id', Unit::query()
                            ->whereIn('site_id', $filters->siteIds)
                            ->select('id'));
                });
            })
            ->orderBy('id')
            ->get();

        $contactIds = $contracts->pluck('contact_id')->map(static fn ($id) => (int) $id)->unique()->values()->all();
        $promisedContactIds = $this->promisedContactIds($contactIds, $promiseSince);

        $openCaseIds = [];
        foreach ($contracts as $contract) {
            $case = $contract->delinquencies->first();
            if ($case instanceof Delinquency) {
                $openCaseIds[] = (int) $case->id;
            }
        }
        $enrolments = $this->enrolmentsByCaseId($openCaseIds);

        $currency = 'EUR';
        $rows = [];
        /** @var array<string, string> $totalsByCurrency */
        $totalsByCurrency = [];
        /** @var array<string, string> $contractBucketTotals */
        $contractBucketTotals = array_fill_keys(AgeingBuckets::KEYS, '0.00');
        /** @var array<string, array{bucket: string, charge_type: string, amount: string, currency: string}> $chargeView */
        $chargeView = [];
        /** @var array<string, string> $chargeBucketTotals */
        $chargeBucketTotals = array_fill_keys(AgeingBuckets::KEYS, '0.00');

        foreach ($contracts as $contract) {
            $overdue = DelinquencyState::overdueCharges($contract, $on);
            if ($overdue->isEmpty()) {
                continue;
            }

            $site = null;
            $unitNumber = '';
            $unit = $contract->unitItem?->item;
            if ($unit instanceof Unit) {
                $site = $unit->site;
                $unitNumber = (string) $unit->unit_number;
            }

            $rowCurrency = strtoupper(trim((string) ($contract->currency ?: $site?->currency ?: 'EUR'))) ?: 'EUR';
            if ($rows === []) {
                $currency = $rowCurrency;
            }

            $days = DelinquencyState::daysOverdue($contract, $on) ?? 0;
            $bucket = AgeingBuckets::fromDays(max(1, $days));

            $rent = '0.00';
            $fees = '0.00';
            $other = '0.00';
            $total = '0.00';

            foreach ($overdue as $charge) {
                $open = $charge->openAmount();
                $type = $charge->charge_type instanceof ChargeType
                    ? $charge->charge_type
                    : ChargeType::from((string) $charge->charge_type);
                $group = AgeingBuckets::amountGroup($type);
                if ($group === 'rent') {
                    $rent = bcadd($rent, $open, 2);
                } elseif ($group === 'fees') {
                    $fees = bcadd($fees, $open, 2);
                } else {
                    $other = bcadd($other, $open, 2);
                }
                $total = bcadd($total, $open, 2);

                $chargeDays = BillingMath::daysBetween(
                    CarbonImmutable::parse($charge->due_date->toDateString()),
                    $on,
                );
                $chargeBucket = AgeingBuckets::fromDays(max(1, $chargeDays));
                $cvKey = $chargeBucket.'|'.$type->value.'|'.$rowCurrency;
                if (! isset($chargeView[$cvKey])) {
                    $chargeView[$cvKey] = [
                        'bucket' => $chargeBucket,
                        'charge_type' => $type->value,
                        'amount' => '0.00',
                        'currency' => $rowCurrency,
                    ];
                }
                $chargeView[$cvKey]['amount'] = BillingMath::round2(
                    bcadd($chargeView[$cvKey]['amount'], $open, 2),
                );
                $chargeBucketTotals[$chargeBucket] = BillingMath::round2(
                    bcadd($chargeBucketTotals[$chargeBucket], $open, 2),
                );
            }

            $rent = BillingMath::round2($rent);
            $fees = BillingMath::round2($fees);
            $other = BillingMath::round2($other);
            $total = BillingMath::round2($total);

            $totalsByCurrency[$rowCurrency] = BillingMath::round2(
                bcadd($totalsByCurrency[$rowCurrency] ?? '0.00', $total, 2),
            );
            $contractBucketTotals[$bucket] = BillingMath::round2(
                bcadd($contractBucketTotals[$bucket], $total, 2),
            );

            $case = $contract->delinquencies->first();
            $lastStep = null;
            $caseStage = null;
            $enrolment = null;
            if ($case instanceof Delinquency) {
                $caseStage = $case->isPaused() ? 'paused' : 'open';
                $step = $case->steps->last();
                if ($step !== null) {
                    $lastStep = $step->action instanceof \BackedEnum
                        ? $step->action->value
                        : (string) $step->action;
                }
                $enrolment = $enrolments[(int) $case->id] ?? null;
            }

            $lastPayment = $contract->payments->first();
            $lastPaymentOn = $lastPayment?->received_on?->toDateString()
                ?? $lastPayment?->created_at?->toDateString();

            $contact = $contract->contact;
            $tenant = $contact !== null
                ? trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''))
                : '';

            $bucketAmounts = array_fill_keys(AgeingBuckets::KEYS, '0.00');
            $bucketAmounts[$bucket] = $total;

            $rows[] = [
                'contract_id' => (int) $contract->id,
                'tenant' => $tenant,
                'site' => (string) ($site?->name ?? ''),
                'unit_number' => $unitNumber,
                'currency' => $rowCurrency,
                'days_overdue' => $days,
                'bucket' => $bucket,
                'bucket_1_7' => $bucketAmounts['1-7'],
                'bucket_8_14' => $bucketAmounts['8-14'],
                'bucket_15_30' => $bucketAmounts['15-30'],
                'bucket_31_60' => $bucketAmounts['31-60'],
                'bucket_60_plus' => $bucketAmounts['60+'],
                'rent' => $rent,
                'fees' => $fees,
                'other' => $other,
                'total' => $total,
                'case_stage' => $caseStage,
                'last_step' => $lastStep,
                'last_payment_on' => $lastPaymentOn,
                'promise_flag' => in_array((int) $contract->contact_id, $promisedContactIds, true) ? 'yes' : 'no',
                'enrolment' => $enrolment,
                'autopay' => $contract->autopay_enabled ? 'yes' : 'no',
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['site'], $a['days_overdue'] * -1, $a['contract_id']]
                <=> [$b['site'], $b['days_overdue'] * -1, $b['contract_id']];
        });

        ksort($totalsByCurrency);
        $totalsList = [];
        foreach ($totalsByCurrency as $cur => $amount) {
            $totalsList[] = ['currency' => $cur, 'amount' => $amount];
        }

        $chargeViewRows = array_values($chargeView);
        usort($chargeViewRows, static fn (array $a, array $b): int => [$a['bucket'], $a['charge_type']]
            <=> [$b['bucket'], $b['charge_type']]);

        return new ReportResult(
            columns: $this->columns($currency),
            rows: $rows,
            meta: [
                'as_of' => $asOf,
                'promise_window_days' => AgeingBuckets::PROMISE_WINDOW_DAYS,
                'totals_by_currency' => $totalsList,
                'contract_bucket_totals' => $contractBucketTotals,
                'charge_bucket_totals' => $chargeBucketTotals,
                'charge_view' => $chargeViewRows,
                'notes' => [
                    'Ageing uses DelinquencyState trigger charge types and site-local as-of.',
                    'Contract view buckets by oldest unpaid charge; charge view buckets each open charge.',
                    'Grand totals of both views reconcile per currency; board chip matches when as_of is site today.',
                ],
            ],
        );
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(string $currency): array
    {
        return [
            ReportColumn::int('contract_id', 'Contract'),
            ReportColumn::string('tenant', 'Tenant'),
            ReportColumn::string('site', 'Site'),
            ReportColumn::string('unit_number', 'Unit'),
            ReportColumn::string('currency', 'Currency'),
            ReportColumn::int('days_overdue', 'Days overdue'),
            ReportColumn::string('bucket', 'Bucket'),
            ReportColumn::money('bucket_1_7', '1–7', $currency),
            ReportColumn::money('bucket_8_14', '8–14', $currency),
            ReportColumn::money('bucket_15_30', '15–30', $currency),
            ReportColumn::money('bucket_31_60', '31–60', $currency),
            ReportColumn::money('bucket_60_plus', '60+', $currency),
            ReportColumn::money('rent', 'Rent', $currency),
            ReportColumn::money('fees', 'Fees', $currency),
            ReportColumn::money('other', 'Other', $currency),
            ReportColumn::money('total', 'Total', $currency),
            ReportColumn::string('case_stage', 'Case stage'),
            ReportColumn::string('last_step', 'Last step'),
            ReportColumn::date('last_payment_on', 'Last payment'),
            ReportColumn::string('promise_flag', 'Promise'),
            ReportColumn::string('enrolment', 'Enrolment'),
            ReportColumn::string('autopay', 'Autopay'),
        ];
    }

    /**
     * @param  list<int>  $contactIds
     * @return list<int>
     */
    private function promisedContactIds(array $contactIds, CarbonImmutable $since): array
    {
        if ($contactIds === []) {
            return [];
        }

        return CallWrapup::query()
            ->where('disposition', 'payment_promised')
            ->where('created_at', '>=', $since->toDateTimeString())
            ->whereHas('message.thread', static function (Builder $q) use ($contactIds): void {
                $q->whereIn('contact_id', $contactIds);
            })
            ->with(['message.thread:id,contact_id'])
            ->get()
            ->map(static fn (CallWrapup $w): int => (int) ($w->message?->thread?->contact_id ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $caseIds
     * @return array<int, string>
     */
    private function enrolmentsByCaseId(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        $active = [
            AutomationRunStatus::Pending->value,
            AutomationRunStatus::Running->value,
            AutomationRunStatus::Waiting->value,
        ];

        /** @var Collection<int, AutomationRun> $runs */
        $runs = AutomationRun::query()
            ->where('subject_type', 'delinquency')
            ->whereIn('subject_id', $caseIds)
            ->whereIn('status', $active)
            ->whereHas('automation', static fn ($q) => $q->whereNotNull('playbook_id'))
            ->with('automation:id,playbook_id')
            ->orderByDesc('id')
            ->get();

        $out = [];
        foreach ($runs as $run) {
            $caseId = (int) $run->subject_id;
            if (isset($out[$caseId])) {
                continue;
            }
            $status = $run->status instanceof AutomationRunStatus
                ? $run->status->value
                : (string) $run->status;
            $out[$caseId] = 'playbook:'.$status;
        }

        return $out;
    }
}
