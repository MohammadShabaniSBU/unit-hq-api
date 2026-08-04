<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\PaymentMethod;
use App\Models\Activity;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daily close / drawer reconciliation: payments by method × employee-causer × site.
 * Definitions: docs/report-definitions.md — Daily close section.
 */
final class DailyCloseReport extends AbstractReport
{
    public static function name(): string
    {
        return 'daily-close';
    }

    public function maxQueries(): int
    {
        return 30;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = OccupancyMetrics::resolveAsOf($filters);

        $payments = Payment::query()
            ->with([
                'contract.unitItem.item' => static function ($morphTo): void {
                    $morphTo->morphWith([
                        Unit::class => ['site:id,name,currency'],
                    ]);
                },
            ])
            ->whereDate('received_on', $asOf)
            ->when($filters->siteIds !== null, function (Builder $q) use ($filters): void {
                $q->whereHas('contract.unitItem', function (Builder $item) use ($filters): void {
                    $item->where('item_type', 'unit')
                        ->whereIn('item_id', Unit::query()
                            ->whereIn('site_id', $filters->siteIds)
                            ->select('id'));
                });
            })
            ->orderBy('id')
            ->get();

        $paymentIds = $payments->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $causers = $this->causersByPaymentId($paymentIds);

        $employeeIds = array_values(array_unique(array_filter(
            array_map(static fn (?array $c): ?int => $c['employee_id'] ?? null, $causers),
        )));
        $employeeNames = $employeeIds === []
            ? []
            : Employee::query()
                ->whereIn('id', $employeeIds)
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(static function (Employee $e): array {
                    return [(int) $e->id => (string) $e->name];
                })
                ->all();

        $currency = 'EUR';
        /** @var array<string, array{
         *     site: string,
         *     method: string,
         *     employee: string,
         *     currency: string,
         *     payment_count: int,
         *     net_amount: string,
         *     provider_refs: list<string>
         * }> $groups */
        $groups = [];
        /** @var array<string, string> $cashByCurrency */
        $cashByCurrency = [];
        /** @var array<string, string> $totalByCurrency */
        $totalByCurrency = [];

        foreach ($payments as $payment) {
            $unit = $payment->contract?->unitItem?->item;
            $site = $unit instanceof Unit ? $unit->site : null;
            $siteName = (string) ($site?->name ?? '');
            $method = $payment->method instanceof PaymentMethod
                ? $payment->method->value
                : (string) ($payment->method ?? 'unknown');
            $rowCurrency = strtoupper(trim((string) ($payment->currency ?: $site?->currency ?: 'EUR'))) ?: 'EUR';
            if ($groups === []) {
                $currency = $rowCurrency;
            }

            $causer = $causers[(int) $payment->id] ?? null;
            $employeeLabel = 'system';
            if ($causer !== null && ($causer['employee_id'] ?? null) !== null) {
                $employeeLabel = $employeeNames[(int) $causer['employee_id']] ?? ('employee#'.$causer['employee_id']);
            }

            $key = $siteName.'|'.$method.'|'.$employeeLabel.'|'.$rowCurrency;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'site' => $siteName,
                    'method' => $method,
                    'employee' => $employeeLabel,
                    'currency' => $rowCurrency,
                    'payment_count' => 0,
                    'net_amount' => '0.00',
                    'provider_refs' => [],
                ];
            }

            $amount = BillingMath::round2((string) $payment->amount);
            $groups[$key]['payment_count']++;
            $groups[$key]['net_amount'] = BillingMath::round2(
                bcadd($groups[$key]['net_amount'], $amount, 2),
            );

            $ref = $payment->stripe_payment_intent_id ?: $payment->reference;
            if (is_string($ref) && $ref !== '') {
                $groups[$key]['provider_refs'][] = $ref;
            }

            $totalByCurrency[$rowCurrency] = BillingMath::round2(
                bcadd($totalByCurrency[$rowCurrency] ?? '0.00', $amount, 2),
            );
            if ($method === PaymentMethod::Cash->value) {
                $cashByCurrency[$rowCurrency] = BillingMath::round2(
                    bcadd($cashByCurrency[$rowCurrency] ?? '0.00', $amount, 2),
                );
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                'site' => $group['site'],
                'method' => $group['method'],
                'employee' => $group['employee'],
                'currency' => $group['currency'],
                'payment_count' => $group['payment_count'],
                'net_amount' => $group['net_amount'],
                'provider_refs' => implode(', ', $group['provider_refs']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['site'], $a['method'], $a['employee']]
            <=> [$b['site'], $b['method'], $b['employee']]);

        ksort($cashByCurrency);
        ksort($totalByCurrency);
        $cashList = [];
        foreach ($cashByCurrency as $cur => $amount) {
            $cashList[] = ['currency' => $cur, 'amount' => $amount];
        }
        $totalList = [];
        foreach ($totalByCurrency as $cur => $amount) {
            $totalList[] = ['currency' => $cur, 'amount' => $amount];
        }

        $primaryCash = $cashList[0]['amount'] ?? '0.00';
        if ($cashList !== []) {
            $currency = $cashList[0]['currency'];
        } elseif ($totalList !== []) {
            $currency = $totalList[0]['currency'];
        }

        return new ReportResult(
            columns: [
                ReportColumn::string('site', 'Site'),
                ReportColumn::string('method', 'Method'),
                ReportColumn::string('employee', 'Employee'),
                ReportColumn::string('currency', 'Currency'),
                ReportColumn::int('payment_count', 'Payments'),
                ReportColumn::money('net_amount', 'Net amount', $currency),
                ReportColumn::string('provider_refs', 'Provider refs'),
            ],
            rows: $rows,
            meta: [
                'as_of' => $asOf,
                'cash_subtotal' => $primaryCash,
                'cash_by_currency' => $cashList,
                'total_by_currency' => $totalList,
                'headlines' => [
                    'cash_subtotal' => [
                        'amount' => $primaryCash,
                        'currency' => $currency,
                        'label' => 'drawer_number',
                    ],
                ],
                'notes' => [
                    'Cash subtotal is the drawer number operators reconcile against.',
                    'Manual payments use received_on; rail payments use settlement date on received_on.',
                    'Causer is the activity-log employee for payment.recorded / payment.reversed; rails show system.',
                    'Reversals appear as negative amounts and net into method × employee subtotals.',
                ],
            ],
        );
    }

    /**
     * @param  list<int>  $paymentIds
     * @return array<int, array{employee_id: int|null}>
     */
    private function causersByPaymentId(array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }

        /** @var Collection<int, Activity> $activities */
        $activities = Activity::query()
            ->where('subject_type', 'payment')
            ->whereIn('subject_id', $paymentIds)
            ->whereIn('description', ['payment.recorded', 'payment.reversed'])
            ->orderBy('id')
            ->get(['subject_id', 'causer_id', 'causer_type', 'description']);

        $out = [];
        foreach ($activities as $activity) {
            $paymentId = (int) $activity->subject_id;
            $causerId = $activity->causer_id !== null ? (int) $activity->causer_id : null;
            // Prefer employee causer; null causer stays system.
            if (! isset($out[$paymentId]) || $causerId !== null) {
                $out[$paymentId] = ['employee_id' => $causerId];
            }
        }

        return $out;
    }
}
